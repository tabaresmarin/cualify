<?php
/**
 * lead_qualifier.php — Motor conversacional y lógica de negocio (Cualify EISO).
 *
 * NOTA IMPORTANTE:
 *   - clasificarPorRendimiento() y consultarRendimientoSitio() viven en pagespeed_api.php.
 *   - construirSlotsPlanoFlow(), parsearSlotFlow() y formatearFranjaLegible() viven en appointment_slots.php.
 *   - enviarMensajeWhatsApp(), enviarListaWhatsApp() viven en whatsapp_api.php.
 *   - crearEventoCalendar() vive en google_calendar_api.php.
 *   NO las redeclares aquí.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/pagespeed_api.php';
require_once __DIR__ . '/google_calendar_api.php';
require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// FUNCIONES AUXILIARES EXCLUSIVAS DE ESTE ARCHIVO
// ============================================================================

if (!function_exists('normalizarUrlSitio')) {
    function normalizarUrlSitio($url) {
        $url = trim($url);
        if ($url === '') return null;

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $partes = parse_url($url);
        if (!isset($partes['host']) || strpos($partes['host'], '.') === false) {
            return null;
        }

        return $url;
    }
}

if (!function_exists('enviarMensajeTexto')) {
    function enviarMensajeTexto($phone, $mensaje) {
        if (function_exists('enviarTextoWhatsApp')) {
            return enviarTextoWhatsApp($phone, $mensaje);
        }
        if (function_exists('enviarMensajeWhatsApp')) {
            return enviarMensajeWhatsApp($phone, $mensaje);
        }
        error_log("[enviarMensajeTexto] (fallback) Para $phone: $mensaje");
        return true;
    }
}

if (!function_exists('actualizarLead')) {
    function actualizarLead(PDO $pdo, $phone, array $campos) {
        if (empty($campos)) return false;

        $sets    = [];
        $valores = [];
        foreach ($campos as $k => $v) {
            $sets[]    = "`$k` = ?";
            $valores[] = $v;
        }
        $valores[] = $phone;

        $sql = "UPDATE leads SET " . implode(', ', $sets) . " WHERE phone = ?";

        try {
            $stmt = $pdo->prepare($sql);
            return $stmt->execute($valores);
        } catch (PDOException $e) {
            error_log('[actualizarLead] Error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('encolarTrabajoPagespeed')) {
    function encolarTrabajoPagespeed(PDO $pdo, $phone, $url, array $payload = []) {
        try {
            $stmt = $pdo->prepare(
                "SELECT id FROM jobs_queue
                 WHERE phone = ? AND job_type = 'pagespeed_analysis'
                   AND status IN ('pending','processing')
                   AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.url')) = ?
                 LIMIT 1"
            );
            $stmt->execute([$phone, $url]);
            if ($stmt->fetch()) {
                return false;
            }
        } catch (PDOException $e) {
            error_log('[encolarTrabajoPagespeed] Validación duplicados omitida: ' . $e->getMessage());
        }

        $payload['url'] = $url;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO jobs_queue (phone, job_type, payload, status, created_at)
                 VALUES (?, 'pagespeed_analysis', ?, 'pending', NOW())"
            );
            $stmt->execute([$phone, $payloadJson]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[encolarTrabajoPagespeed] Error INSERT: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('reconectarSiEsNecesario')) {
    function reconectarSiEsNecesario(?PDO $pdo): PDO {
        try {
            if ($pdo !== null) {
                $pdo->query('SELECT 1');
                return $pdo;
            }
        } catch (PDOException $e) {
            error_log('[reconectarSiEsNecesario] Conexión perdida, reconectando: ' . $e->getMessage());
        }

        error_log('[reconectarSiEsNecesario] Creando nueva conexión PDO...');
        $nueva = obtenerConexion(true);
        error_log('[reconectarSiEsNecesario] Nueva conexión PDO establecida.');
        return $nueva;
    }
}

// ============================================================================
// PROCESAMIENTO ASÍNCRONO DEL LEAD (post-Flow) — PDO
// ============================================================================

function procesarLeadFlowAsincrono($phone, PDO &$pdo) {
    try {
        $stmt = $pdo->prepare("SELECT name, email, url_sitio FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[procesarLeadFlowAsincrono] Error SELECT: ' . $e->getMessage());
        return false;
    }

    if (!$lead || empty($lead['url_sitio'])) {
        error_log("[procesarLeadFlowAsincrono] Sin lead o URL para $phone");
        return false;
    }

    $nombre = !empty($lead['name']) ? $lead['name'] : 'estimado cliente';
    $url    = $lead['url_sitio'];

    // Mensaje de agradecimiento inmediato
    $mensajeGracias = "¡Gracias, {$nombre}! 🎉\n\n"
                    . "Recibimos tu sitio: {$url}\n"
                    . "Estoy analizando su rendimiento. Te enviaré el diagnóstico en un momento...";
    enviarMensajeTexto($phone, $mensajeGracias);

    // Análisis de PageSpeed
    $resultado = consultarRendimientoSitio($url);

    // Reconectar MySQL tras la espera larga
    $pdo = reconectarSiEsNecesario($pdo);

    $score        = 0;
    $scoreBucket  = 'error';
    $scoreMessage = 'No pudimos analizar tu sitio automáticamente. Un asesor lo revisará contigo.';

    if (!empty($resultado['ok'])) {
        $score        = (int) $resultado['score'];
        $clasif       = clasificarPorRendimiento($score);
        $scoreBucket  = $clasif['clave'];
        $scoreMessage = $clasif['mensaje'];
    } else {
        error_log("[procesarLeadFlowAsincrono] PageSpeed falló para $url: " . ($resultado['error'] ?? 'desconocido'));
        $errorDetalle = $resultado['error'] ?? '';
        if (stripos($errorDetalle, 'timeout') !== false || stripos($errorDetalle, 'timed out') !== false) {
            $scoreMessage = "Tu sitio tardó demasiado en responder al análisis automático. Esto puede indicar problemas serios de rendimiento. Un asesor lo revisará manualmente y te contactará pronto.";
        } else {
            $scoreMessage = "No pudimos analizar tu sitio automáticamente. Un asesor lo revisará y te contactará pronto.";
        }
    }

    // Actualizar el lead
    actualizarLead($pdo, $phone, [
        'pagespeed_score' => (string) $score,
        'clasificacion'   => $scoreBucket,
    ]);

    // Enviar diagnóstico
    $mensajeDiagnostico = "📊 *Diagnóstico de {$url}*\n\n"
                        . "Puntuación de rendimiento: *{$score}/100*\n"
                        . $scoreMessage . "\n\n"
                        . "¿Quieres agendar una llamada de asesoría? Responde *AGENDAR* y te muestro los horarios disponibles.";
    enviarMensajeTexto($phone, $mensajeDiagnostico);

    $pdo = reconectarSiEsNecesario($pdo);

    // Marcar lead en step 2 (listo para agendar)
    actualizarLead($pdo, $phone, ['step' => '2']);

    return true;
}

// ============================================================================
// MOTOR CONVERSACIONAL — PDO
// ============================================================================

function procesarMensajeEntrante($phone, $texto, PDO $pdo) {
    $textoLower = strtolower(trim($texto));

    // Log de diagnóstico
    error_log("[procesarMensajeEntrante] phone=$phone texto='$texto'");

    // Obtener estado actual del lead
    try {
        $stmt = $pdo->prepare("SELECT step, status, url_sitio, pagespeed_score, clasificacion, cita_fecha, cita_hora FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[procesarMensajeEntrante] Error SELECT: ' . $e->getMessage());
        return;
    }

    if (!$lead) {
        // Lead nuevo
        try {
            $stmt = $pdo->prepare("INSERT INTO leads (phone, step, status) VALUES (?, 1, 'en_calificacion')");
            $stmt->execute([$phone]);
        } catch (PDOException $e) {
            error_log('[procesarMensajeEntrante] Error INSERT: ' . $e->getMessage());
        }

        enviarMensajeTexto($phone, "¡Hola! Soy el asistente de EISO. Para comenzar, envíame la URL de tu sitio web (ej. https://tusitio.com) y te haré un diagnóstico gratuito.");
        return;
    }

    $step   = (string) ($lead['step'] ?? '0');
    $status = $lead['status'] ?? '';

    // ========================================================================
    // STEP 99: CITA YA AGENDADA
    // No ofrecer agendar de nuevo. Ofrecer opciones útiles.
    // ========================================================================
    if ($step === '99' || $status === 'calificado') {
        // Si el usuario quiere cancelar o reprogramar
        if (strpos($textoLower, 'cancel') !== false || strpos($textoLower, 'reprogram') !== false || strpos($textoLower, 'cambiar') !== false) {
            // Volver a step 2 y mostrar los slots disponibles
            actualizarLead($pdo, $phone, ['step' => '2', 'status' => 'en_calificacion']);
            enviarMensajeTexto($phone, "Sin problema. Vamos a reagendar tu cita. Te muestro los horarios disponibles:");
            enviarListaSlots($phone, $pdo);
            return;
        }

        // Si dice "agendar" estando ya agendado
        if (strpos($textoLower, 'agendar') !== false) {
            $fecha = $lead['cita_fecha'] ?? '';
            $hora  = $lead['cita_hora'] ?? '';
            enviarMensajeTexto($phone,
                "Ya tienes una cita agendada para el *{$fecha}* a las *{$hora}*.\n\n"
                . "Si necesitas *cancelar* o *reprogramar*, solo dime."
            );
            return;
        }

        // Cualquier otro mensaje: responder de forma amable y contextual
        $fecha = $lead['cita_fecha'] ?? '';
        $hora  = $lead['cita_hora'] ?? '';
        enviarMensajeTexto($phone,
            "¡Gracias por escribir! Tu cita ya está confirmada para el *{$fecha}* a las *{$hora}*.\n\n"
            . "Si necesitas *cancelar* o *reprogramar*, solo dime. Un asesor de EISO se pondrá en contacto contigo pronto."
        );
        return;
    }

    // ========================================================================
    // Usuario responde "AGENDAR" → Mostrar lista de slots
    // ========================================================================
    if (strpos($textoLower, 'agendar') !== false) {
        enviarListaSlots($phone, $pdo);
        return;
    }

    // ========================================================================
    // Usuario responde con un número (1-5) → Selección por texto
    // ========================================================================
    if (preg_match('/^\s*([1-9])\s*$/', $textoLower, $m)) {
        $indice = (int) $m[1];
        $slots  = construirSlotsPlanoFlow(5);
        if (isset($slots[$indice - 1])) {
            procesarSeleccionSlot($phone, $slots[$indice - 1]['id'], $pdo);
            return;
        }
    }

    // ========================================================================
    // Usuario está en STEP 1 → Esperando URL
    // ========================================================================
    if ($step === '1') {
        $url = normalizarUrlSitio($texto);
        if ($url === null) {
            enviarMensajeTexto($phone, "No pude reconocer una URL válida. Por favor envíala con formato https://tusitio.com");
            return;
        }

        actualizarLead($pdo, $phone, ['url_sitio' => $url, 'step' => '1.5']);
        enviarMensajeTexto($phone, "Perfecto, analizando {$url}... ⏳");

        $resultado = consultarRendimientoSitio($url);
        $pdo = reconectarSiEsNecesario($pdo);

        $score  = 0;
        $bucket = 'error';
        $msg    = 'No pudimos analizarlo. Un asesor te contactará.';

        if (!empty($resultado['ok'])) {
            $score  = (int) $resultado['score'];
            $clasif = clasificarPorRendimiento($score);
            $bucket = $clasif['clave'];
            $msg    = $clasif['mensaje'];
        }

        actualizarLead($pdo, $phone, [
            'pagespeed_score' => (string) $score,
            'clasificacion'   => $bucket,
            'step'            => '2',
        ]);

        enviarMensajeTexto($phone, "📊 *Diagnóstico:*\nPuntuación: *{$score}/100*\n{$msg}\n\n¿Quieres agendar una llamada? Responde *AGENDAR*.");
        return;
    }

    // ========================================================================
    // STEP 2: Ya tiene diagnóstico, esperando que agende
    // ========================================================================
    if ($step === '2') {
        // Si el usuario escribe algo que no es "AGENDAR" ni un número,
        // recordarle amablemente las opciones.
        enviarMensajeTexto($phone,
            "¿Listo para agendar tu asesoría? Responde *AGENDAR* y te muestro los horarios disponibles.\n\n"
            . "Si tu sitio cambió o quieres analizar otro, escribe *URL*."
        );
        return;
    }

    // ========================================================================
    // STEP 1.5: Análisis en curso (raro, pero por si acaso)
    // ========================================================================
    if ($step === '1.5') {
        enviarMensajeTexto($phone, "Estoy analizando tu sitio, dame un momento por favor. Te escribiré en cuanto termine.");
        return;
    }

    // ========================================================================
    // FALLBACK: Cualquier otro estado
    // ========================================================================
    if (strpos($textoLower, 'url') !== false) {
        // El usuario quiere enviar una URL, pero no está en step 1
        actualizarLead($pdo, $phone, ['step' => '1']);
        enviarMensajeTexto($phone, "Perfecto, envíame la URL del sitio que quieres analizar (ej. https://tusitio.com).");
        return;
    }

    enviarMensajeTexto($phone,
        "Entiendo. Para continuar, puedes:\n\n"
        . "• Escribir *AGENDAR* si quieres agendar una llamada.\n"
        . "• Escribir *URL* si quieres analizar un sitio web.\n"
        . "• Escribir *AYUDA* si necesitas asistencia."
    );
}

/**
 * Envía la lista interactiva de slots disponibles.
 */
function enviarListaSlots($phone, PDO $pdo) {
    $slots = construirSlotsPlanoFlow(5);

    if (empty($slots)) {
        enviarMensajeTexto($phone, "No hay horarios disponibles en este momento. Un asesor te contactará pronto para coordinar.");
        return;
    }

    // Convertir slots al formato de secciones de WhatsApp
    $rows = [];
    foreach ($slots as $slot) {
        $rows[] = [
            'id'          => $slot['id'],
            'title'       => mb_substr($slot['title'], 0, 24), // máx 24 chars
            'description' => 'Toca para agendar esta franja',
        ];
    }

    $secciones = [[
        'title' => 'Próximos horarios',  // máx 24 chars
        'rows'  => $rows,
    ]];

    enviarListaWhatsApp(
        $phone,
        "📅 *Horarios disponibles:*\n\nElige la franja que prefieras para tu asesoría gratuita.",
        "Ver horarios",  // máx 20 chars
        $secciones
    );

    // Marcar como step 2 (en proceso de agendamiento)
    actualizarLead($pdo, $phone, ['step' => '2']);
}

/**
 * Procesa la selección de un slot (ya sea por lista interactiva o por texto).
 */
function procesarSeleccionSlot($phone, $slotId, PDO $pdo) {
    $franja = parsearSlotFlow($slotId);
    if ($franja === null) {
        enviarMensajeTexto($phone, "No pude reconocer ese horario. Responde *AGENDAR* para ver las opciones de nuevo.");
        return;
    }

    // Obtener datos del lead para el evento
    try {
        $stmt = $pdo->prepare("SELECT name, email, url_sitio, pagespeed_score FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[procesarSeleccionSlot] Error SELECT: ' . $e->getMessage());
        $lead = [];
    }

    $descripcionEvento = "Lead calificado vía WhatsApp.\n"
        . "Teléfono: $phone\n"
        . "Nombre: " . ($lead['name'] ?? '') . "\n"
        . "Email: " . ($lead['email'] ?? '') . "\n"
        . "Sitio web: " . ($lead['url_sitio'] ?? '') . "\n"
        . "Puntaje PageSpeed: " . ($lead['pagespeed_score'] ?? '') . "/100\n";

    // Crear el evento en Google Calendar
    try {
        $resultadoEvento = crearEventoCalendar(
            'Llamada con lead ' . $phone,
            $descripcionEvento,
            $franja['inicio'],
            $franja['fin'],
            $lead['email'] ?? null
        );
    } catch (Throwable $e) {
        error_log('[procesarSeleccionSlot] Excepción Calendar: ' . $e->getMessage());
        $resultadoEvento = ['ok' => false, 'error' => $e->getMessage()];
    }

    $pdo = reconectarSiEsNecesario($pdo);

    if (empty($resultadoEvento['ok'])) {
        error_log('[procesarSeleccionSlot] Error Calendar: ' . ($resultadoEvento['error'] ?? 'desconocido'));
        enviarMensajeTexto($phone, "Hubo un problema agendando tu cita. Por favor intenta de nuevo con *AGENDAR*.");
        return;
    }

    // Actualizar el lead con los datos de la cita
    actualizarLead($pdo, $phone, array_filter([
        'status'          => 'calificado',
        'step'            => '99',
        'cita_fecha'      => $franja['inicio']->format('Y-m-d'),
        'cita_hora'       => $franja['inicio']->format('H:i:s'),
        'google_event_id' => $resultadoEvento['event_id'] ?? null,
    ]));

    // Confirmar al usuario
    $label = formatearFranjaLegible($franja['inicio']);
    enviarMensajeTexto($phone,
        "✅ *Cita confirmada*\n\n"
        . "📅 {$label}\n"
        . "⏱️ Duración: 30 minutos\n\n"
        . "Te esperamos. Recibirás un recordatorio antes de la llamada."
    );
}