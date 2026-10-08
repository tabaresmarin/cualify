<?php
/**
 * lead_qualifier.php — Motor conversacional y lógica de negocio (Cualify EISO).
 *
 * ARQUITECTURA:
 *   - El webhook.php ENCOLA los mensajes entrantes en `jobs_queue` (job_type = 'ia_message').
 *   - El worker_ia.php (cron cada minuto) toma esos jobs, llama a la IA y envía la respuesta.
 *   - El worker_pagespeed.php (cron cada minuto) procesa los análisis de PageSpeed.
 *   - Esta separación evita que el webhook supere el timeout de 20-30s de Meta.
 *
 * NOTAS:
 *   - clasificarPorRendimiento() y consultarRendimientoSitio() viven en pagespeed_api.php.
 *   - consultarRendimientoRapidoConCrUX() vive en crux_api.php.
 *   - construirSlotsPlanoFlow(), parsearSlotFlow(), formatearFranjaLegible() viven en appointment_slots.php.
 *   - enviarMensajeWhatsApp(), enviarTextoWhatsApp(), enviarListaWhatsApp() viven en whatsapp_api.php.
 *   - crearEventoCalendar() vive en google_calendar_api.php.
 *   - procesarConIA() vive en ia_engine.php.
 *   - Todas las funciones de BD usan PDO.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/pagespeed_api.php';
require_once __DIR__ . '/crux_api.php';
require_once __DIR__ . '/google_calendar_api.php';
require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// UTILIDADES
// ============================================================================

if (!function_exists('normalizarUrlSitio')) {
    function normalizarUrlSitio($url) {
        $url = trim($url);
        if ($url === '') return null;

        // Quitar protocolo si viene
        $url = preg_replace('#^https?://#i', '', $url);
        // Quitar "www." inicial
        $url = preg_replace('#^www\.#i', '', $url);
        // Añadir https://
        $url = 'https://' . $url;

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
// ENCOLADO DE JOBS
// ============================================================================

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

/**
 * Encola un mensaje entrante para ser procesado en background por worker_ia.php.
 *
 * Acepta dos formatos:
 *   - String: "Hola, quiero info"  → se envuelve como tipo 'text'
 *   - Array:  ['tipo' => 'slot_selection', 'slot_id' => '...']
 */
if (!function_exists('encolarMensajeIA')) {
    function encolarMensajeIA(PDO $pdo, string $phone, $textoOrPayload): int|false {
        // Normalizar a array
        if (is_string($textoOrPayload)) {
            $payload = ['tipo' => 'text', 'texto' => $textoOrPayload];
        } elseif (is_array($textoOrPayload)) {
            $payload = $textoOrPayload;
        } else {
            error_log('[encolarMensajeIA] Payload inválido: ' . gettype($textoOrPayload));
            return false;
        }

        try {
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $stmt = $pdo->prepare(
                "INSERT INTO jobs_queue (phone, job_type, payload, status, created_at)
                 VALUES (?, 'ia_message', ?, 'pending', NOW())"
            );
            $stmt->execute([$phone, $payloadJson]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log('[encolarMensajeIA] Error: ' . $e->getMessage());
            return false;
        }
    }
}

// ============================================================================
// PROCESAMIENTO ASÍNCRONO DEL LEAD (post-Flow) — corre en worker_pagespeed.php
// ============================================================================

/**
 * Procesa un lead recolectado por el Flow de forma asíncrona.
 * SÍ envía mensajes directamente porque ya corre en background.
 */
function procesarLeadFlowAsincrono($phone, PDO &$pdo, array $payload = []) {
    // 1. Obtener los datos del lead
    try {
        $stmt = $pdo->prepare("SELECT name, email, url_sitio FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[procesarLeadFlowAsincrono] Error SELECT: ' . $e->getMessage());
        return false;
    }

    if (!$lead) {
        error_log("[procesarLeadFlowAsincrono] Sin lead para $phone");
        return false;
    }

    // ⭐ PRIORIDAD: usar la URL del payload. Si no hay, usar la del lead.
    // (YA NO se reasigna $url después)
    $url = !empty($payload['url']) ? $payload['url'] : ($lead['url_sitio'] ?? '');

    if (empty($url)) {
        error_log("[procesarLeadFlowAsincrono] Sin URL para $phone");
        return false;
    }

    $nombre = !empty($lead['name']) ? $lead['name'] : 'estimado cliente';

    // Mensaje de agradecimiento
    $mensajeGracias = "¡Gracias, {$nombre}! 🎉\n\n"
                    . "Recibimos tu sitio: {$url}\n"
                    . "Estoy analizando su rendimiento. Te enviaré el diagnóstico en un momento...";
    enviarMensajeTexto($phone, $mensajeGracias);

    // Análisis de PageSpeed
    $resultado = consultarRendimientoSitio($url);
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

    actualizarLead($pdo, $phone, [
        'pagespeed_score' => (string) $score,
        'clasificacion'   => $scoreBucket,
    ]);

    $mensajeDiagnostico = "📊 *Diagnóstico de {$url}*\n\n"
                        . "Puntuación de rendimiento: *{$score}/100*\n"
                        . $scoreMessage . "\n\n"
                        . "¿Quieres agendar una llamada de asesoría? Responde *AGENDAR* y te muestro los horarios disponibles.";
    enviarMensajeTexto($phone, $mensajeDiagnostico);

    $pdo = reconectarSiEsNecesario($pdo);
    actualizarLead($pdo, $phone, ['step' => '2']);

    return true;
}

// ============================================================================
// MOTOR CONVERSACIONAL — CORRE EN worker_ia.php (devuelve texto)
// ============================================================================

/**
 * Procesa un mensaje entrante y DEVUELVE el texto a enviar.
 * El worker_ia.php se encarga de enviarlo por WhatsApp.
 */
function procesarMensajeEntranteConIA($phone, $texto, PDO $pdo): ?string {
    $textoLower = strtolower(trim($texto));

    error_log("[procesarMensajeEntranteConIA] phone=$phone texto='$texto'");

    // ========================================================================
    // FILTRO RÁPIDO: BÚSQUEDA DE EMPLEO / VACANTES (Descarte automático)
    // ========================================================================
    $patronesEmpleo = [
        'empleo', 'trabajo', 'vacante', 'vacantes', 'hoja de vida', 'hojas de vida',
        'enviar cv', 'adjunto cv', 'practicas', 'prácticas', 'postularme', 'bolsa de trabajo'
    ];
    foreach ($patronesEmpleo as $patron) {
        if (strpos($textoLower, $patron) !== false) {
            actualizarLead($pdo, $phone, [
                'status'        => 'descartado',
                'clasificacion' => 'Descartado: Búsqueda de empleo'
            ]);
            return "Gracias por tu interés en EISO. En este momento no contamos con vacantes ni procesos de selección abiertos.\n\n"
                 . "¡Te deseamos mucho éxito en tu búsqueda laboral!";
        }
    }

    // ========================================================================
    // COMANDO ASESOR (handoff a humano)
    // ========================================================================
    if (
        strpos($textoLower, 'asesor') !== false
        || strpos($textoLower, 'humano') !== false
        || strpos($textoLower, 'persona') !== false
        || strpos($textoLower, 'agente') !== false
    ) {
        // El enum de leads.status es ('nuevo','en_calificacion','calificado',
        // 'descartado'): no tiene un estado propio para el handoff a humano, asi
        // que se registra como 'descartado' (lead que el bot no puede resolver y
        // pasa a un asesor). No escribir 'requiere_humano' ni 'new': son valores
        // fuera del enum y el UPDATE falla.
        actualizarLead($pdo, $phone, ['status' => 'descartado']);
        notificarEquipoVentas($phone, $pdo);
        return "Entendido 🙌. Un asesor de EISO se pondrá en contacto contigo muy pronto.\n\n"
             . "Mientras tanto, ¿puedo ayudarte con algo más?";
    }

    // ========================================================================
    // COMANDOS EXPLÍCITOS (no pasan por IA, ahorran tokens)
    // ========================================================================

    // Comando AGENDAR
    if (strpos($textoLower, 'agendar') !== false) {
        $slots = construirSlotsPlanoFlow(5);
        if (empty($slots)) {
            return "No hay horarios disponibles en este momento. Un asesor te contactará pronto.";
        }
        $lista = "📅 *Horarios disponibles:*\n\n";
        foreach ($slots as $i => $slot) {
            $lista .= ($i + 1) . ". {$slot['title']}\n";
        }
        $lista .= "\nResponde con el número de la opción que prefieras.";
        actualizarLead($pdo, $phone, ['step' => '2']);
        return $lista;
    }

    // Selección numérica de slot
    if (preg_match('/^\s*([1-9])\s*$/', $textoLower, $m)) {
        $indice = (int) $m[1];
        $slots  = construirSlotsPlanoFlow(5);
        if (isset($slots[$indice - 1])) {
            return procesarSeleccionSlotYDevolverTexto($phone, $slots[$indice - 1]['id'], $pdo);
        }
    }

    // ========================================================================
    // OBTENER ESTADO DEL LEAD
    // ========================================================================
    try {
        $stmt = $pdo->prepare("SELECT step, status FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[procesarMensajeEntranteConIA] Error: ' . $e->getMessage());
        $lead = null;
    }

    // Lead nuevo: crear y delegar a IA
    if (!$lead) {
        try {
            $stmt = $pdo->prepare("INSERT INTO leads (phone, step, status) VALUES (?, 1, 'en_calificacion')");
            $stmt->execute([$phone]);
        } catch (PDOException $e) {
            error_log('[procesarMensajeEntranteConIA] Error INSERT: ' . $e->getMessage());
        }
        $step   = '1';
        $status = 'en_calificacion';
    } else {
        $step   = (string) ($lead['step'] ?? '1');
        $status = $lead['status'] ?? 'en_calificacion';
    }

    // ========================================================================
    // CITA YA AGENDADA (step 99)
    // ========================================================================
    if ($step === '99' || $status === 'calificado') {
        // Cancelar o reprogramar
        if (strpos($textoLower, 'cancel') !== false || strpos($textoLower, 'reprogram') !== false || strpos($textoLower, 'cambiar') !== false) {
            actualizarLead($pdo, $phone, ['step' => '2', 'status' => 'en_calificacion']);
            $slots = construirSlotsPlanoFlow(5);
            $lista = "Sin problema. Vamos a reagendar tu cita.\n\n📅 *Horarios disponibles:*\n\n";
            foreach ($slots as $i => $slot) {
                $lista .= ($i + 1) . ". {$slot['title']}\n";
            }
            $lista .= "\nResponde con el número de la opción que prefieras.";
            return $lista;
        }

        // Delegar a IA para saludos/agradecimientos
        require_once __DIR__ . '/ia_engine.php';
        return procesarConIA($phone, $texto, $pdo);
    }

    // ========================================================================
    // STEP 1: ESPERANDO URL (con consulta rápida CrUX)
    // ========================================================================
    // En lead_qualifier.php, dentro de procesarMensajeEntranteConIA()

    // ========================================================================
    // STEP 1: ESPERANDO URL (con caché y CrUX)
    // ========================================================================
    if ($step === '1') {
        $url = normalizarUrlSitio($texto);

        if ($url !== null) {
            // 1. Actualizar el lead con la URL
            actualizarLead($pdo, $phone, ['url_sitio' => $url, 'step' => '1.5']);

            // 2. ⭐ CONSULTAR CACHÉ PRIMERO: si el sitio fue analizado en las últimas 24h,
            //    devolvemos el diagnóstico COMPLETO inmediatamente.
            $cacheResult = obtenerDeCachePageSpeed($url);

            if ($cacheResult !== null) {
                error_log("[procesarMensajeEntranteConIA] Cache HIT para $url (score: {$cacheResult['score']})");

                $score  = (int) $cacheResult['score'];
                $clasif = clasificarPorRendimiento($score);

                actualizarLead($pdo, $phone, [
                    'pagespeed_score' => (string) $score,
                    'clasificacion'   => $clasif['clave'],
                    'step'            => '2',
                ]);

                return "¡Ya tenía un diagnóstico reciente de {$url}! 🚀\n\n"
                     . "📊 *Diagnóstico:*\n"
                     . "Puntuación de rendimiento: *{$score}/100*\n"
                     . $clasif['mensaje'] . "\n\n"
                     . "¿Quieres agendar una llamada de asesoría? Responde *AGENDAR*.";
            }

            // 3. Cache MISS: respuesta rápida con CrUX + encolar PSI
            $cruxResult = consultarRendimientoRapidoConCrUX($url, valorEntorno('PSI_API_KEY'));

            // 4. Encolar el análisis completo de PSI (con flag para omitir el saludo duplicado)
            $jobPsId = encolarTrabajoPagespeed($pdo, $phone, $url, ['skip_greeting' => true]);
            if ($jobPsId) {
                dispararWorker('pagespeed');  // ⭐ Disparar worker inmediatamente
            }

            // 5. Construir la respuesta inmediata
            $respuesta = "¡Recibí tu sitio: {$url}! 🚀\n\n";

            if (!empty($cruxResult['ok']) && $cruxResult['score'] !== null) {
                $cruxScore = (int) $cruxResult['score'];
                $respuesta .= "⚡ Según datos de usuarios reales de Chrome, tu sitio tiene una puntuación de rendimiento de *{$cruxScore}/100*. ";
                $respuesta .= "Estoy realizando un análisis más detallado en segundo plano y te enviaré los resultados completos en 1-2 minutos.\n\n";
                actualizarLead($pdo, $phone, ['pagespeed_score' => (string) $cruxScore]);
            } else {
                $respuesta .= "He lanzado un análisis detallado de tu sitio. Los resultados pueden tardar 1-2 minutos; te los enviaré por aquí en cuanto termine.\n\n";
            }

            $respuesta .= "Mientras tanto, ¿me cuentas qué objetivos tienes para tu sitio web? "
                        . "Si quieres hablar con un asesor, escribe *AGENDAR*.";

            actualizarLead($pdo, $phone, ['step' => '2']);
            return $respuesta;
        }
    }

    // ========================================================================
    // DELEGAR A LA IA (para step 2, saludos, agradecimientos, etc.)
    // ========================================================================
    try {
        require_once __DIR__ . '/ia_engine.php';
        return procesarConIA($phone, $texto, $pdo);
    } catch (Throwable $e) {
        error_log("[procesarMensajeEntranteConIA] IA falló: " . $e->getMessage());
        return "Estoy teniendo problemas técnicos en este momento. 🔧\n\n"
             . "Mientras tanto, puedes:\n"
             . "• Escribir *AGENDAR* para ver horarios disponibles\n"
             . "• Enviar la URL de tu sitio para un análisis gratuito\n"
             . "• Escribir *ASESOR* para que un humano te contacte";
    }
}

/**
 * Procesa la selección de un slot y DEVUELVE el texto de confirmación.
 */
function procesarSeleccionSlotYDevolverTexto($phone, $slotId, PDO $pdo): string {
    $franja = parsearSlotFlow($slotId);
    if ($franja === null) {
        return "No pude reconocer ese horario. Responde *AGENDAR* para ver las opciones de nuevo.";
    }

    // Obtener datos del lead
    try {
        $stmt = $pdo->prepare("SELECT name, email, url_sitio, pagespeed_score FROM leads WHERE phone = ? LIMIT 1");
        $stmt->execute([$phone]);
        $lead = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[procesarSeleccionSlotYDevolverTexto] Error SELECT: ' . $e->getMessage());
        $lead = [];
    }

    $descripcionEvento = "Lead calificado vía WhatsApp.\n"
        . "Teléfono: $phone\n"
        . "Nombre: " . ($lead['name'] ?? '') . "\n"
        . "Email: " . ($lead['email'] ?? '') . "\n"
        . "Sitio web: " . ($lead['url_sitio'] ?? '') . "\n"
        . "Puntaje PageSpeed: " . ($lead['pagespeed_score'] ?? '') . "/100\n";

    // Crear evento en Calendar
    try {
        $resultadoEvento = crearEventoCalendar(
            'Llamada con lead ' . $phone,
            $descripcionEvento,
            $franja['inicio'],
            $franja['fin'],
            $lead['email'] ?? null
        );
    } catch (Throwable $e) {
        error_log('[procesarSeleccionSlotYDevolverTexto] Excepción Calendar: ' . $e->getMessage());
        $resultadoEvento = ['ok' => false, 'error' => $e->getMessage()];
    }

    $pdo = reconectarSiEsNecesario($pdo);

    if (empty($resultadoEvento['ok'])) {
        error_log('[procesarSeleccionSlotYDevolverTexto] Error Calendar: ' . ($resultadoEvento['error'] ?? 'desconocido'));
        return "Hubo un problema agendando tu cita. Por favor intenta de nuevo con *AGENDAR*.";
    }

    // Actualizar el lead
    actualizarLead($pdo, $phone, array_filter([
        'status'          => 'calificado',
        'step'            => '99',
        'cita_fecha'      => $franja['inicio']->format('Y-m-d'),
        'cita_hora'       => $franja['inicio']->format('H:i:s'),
        'google_event_id' => $resultadoEvento['event_id'] ?? null,
    ]));

    $label = formatearFranjaLegible($franja['inicio']);
    return "✅ *Cita confirmada*\n\n"
         . "📅 {$label}\n"
         . "⏱️ Duración: 30 minutos\n\n"
         . "Te esperamos. Recibirás un recordatorio antes de la llamada.";
}

// ============================================================================
// COMPATIBILIDAD: versión legacy que envía directamente
// ============================================================================

/**
 * Versión legacy que envía mensajes directamente.
 */
function procesarMensajeEntrante($phone, $texto, PDO $pdo) {
    $respuesta = procesarMensajeEntranteConIA($phone, $texto, $pdo);
    if ($respuesta !== null && $respuesta !== '') {
        enviarMensajeTexto($phone, $respuesta);
    }
}

/**
 * Versión legacy que envía directamente la confirmación de slot.
 */
function procesarSeleccionSlot($phone, $slotId, PDO $pdo) {
    $respuesta = procesarSeleccionSlotYDevolverTexto($phone, $slotId, $pdo);
    enviarMensajeTexto($phone, $respuesta);
}

/**
 * Envía la lista interactiva de slots.
 */
function enviarListaSlots($phone, PDO $pdo) {
    $slots = construirSlotsPlanoFlow(5);

    if (empty($slots)) {
        enviarMensajeTexto($phone, "No hay horarios disponibles en este momento. Un asesor te contactará pronto para coordinar.");
        return;
    }

    $rows = [];
    foreach ($slots as $slot) {
        $rows[] = [
            'id'          => $slot['id'],
            'title'       => mb_substr($slot['title'], 0, 24),
            'description' => 'Toca para agendar esta franja',
        ];
    }

    $secciones = [[
        'title' => 'Próximos horarios',
        'rows'  => $rows,
    ]];

    enviarListaWhatsApp(
        $phone,
        "📅 *Horarios disponibles:*\n\nElige la franja que prefieras para tu asesoría gratuita.",
        "Ver horarios",
        $secciones
    );

    actualizarLead($pdo, $phone, ['step' => '2']);
}

// ============================================================================
// RATE LIMITING
// ============================================================================

/**
 * Verifica si un lead puede seguir usando la IA (máx 5 mensajes por minuto).
 */
if (!function_exists('puedeUsarIA')) {
    function puedeUsarIA(string $phone, PDO $pdo): bool {
        try {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM jobs_queue
                 WHERE phone = ? AND job_type = 'ia_message'
                 AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"
            );
            $stmt->execute([$phone]);
            return (int) $stmt->fetchColumn() < 5;
        } catch (PDOException $e) {
            error_log('[puedeUsarIA] Error: ' . $e->getMessage());
            return true;
        }
    }
}

// ============================================================================
// HANDOFF A VENDEDOR HUMANO
// ============================================================================

/**
 * Notifica al equipo de ventas que un lead requiere atención humana.
 */
if (!function_exists('notificarEquipoVentas')) {
    function notificarEquipoVentas(string $phone, PDO $pdo): void {
        $whatsappEquipo = valorEntorno('VENTAS_WHATSAPP_NOTIFY');
        if (empty($whatsappEquipo)) {
            error_log('[notificarEquipoVentas] VENTAS_WHATSAPP_NOTIFY no configurado');
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT name, email, url_sitio, pagespeed_score, clasificacion
                 FROM leads WHERE phone = ? LIMIT 1"
            );
            $stmt->execute([$phone]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $lead = [];
        }

        $resumen = "🔔 *NUEVO LEAD REQUIERE ASESOR*\n\n"
                 . "📱 Teléfono: {$phone}\n"
                 . "👤 Nombre: " . ($lead['name'] ?? 'N/A') . "\n"
                 . "📧 Email: " . ($lead['email'] ?? 'N/A') . "\n"
                 . "🌐 Sitio: " . ($lead['url_sitio'] ?? 'N/A') . "\n"
                 . "📊 PageSpeed: " . ($lead['pagespeed_score'] ?? 'N/A') . "/100\n"
                 . "🏷️ Clasificación: " . ($lead['clasificacion'] ?? 'N/A') . "\n"
                 . "⏰ " . date('Y-m-d H:i:s');

        try {
            enviarTextoWhatsApp($whatsappEquipo, $resumen);
            error_log("[notificarEquipoVentas] Notificación enviada a $whatsappEquipo");
        } catch (Throwable $e) {
            error_log('[notificarEquipoVentas] Error enviando: ' . $e->getMessage());
        }
    }
}

/**
 * Dispara un worker en background vía HTTP interno.
 * No bloquea, no espera respuesta.
 *
 * @param string $worker 'ia' o 'pagespeed'
 */
function dispararWorker(string $worker): void {
    $token = valorEntorno('WORKER_TRIGGER_TOKEN');
    $url   = "https://eiso.com.co/cualify/trigger_worker.php?worker={$worker}&token={$token}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,        // Timeout bajo, no nos importa la respuesta
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    // No esperamos la respuesta, solo disparamos
    curl_exec($ch);
    curl_close($ch);
}