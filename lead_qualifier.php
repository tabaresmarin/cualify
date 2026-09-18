<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/pagespeed_api.php';
require_once __DIR__ . '/google_calendar_api.php';
require_once __DIR__ . '/appointment_slots.php';

function obtenerConexion() {
    return new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
}

/**
 * Actualiza columnas arbitrarias del lead identificado por $phone.
 * $campos es un array asociativo ['columna' => valor]. Solo usa columnas de una whitelist fija.
 */
function actualizarLead(mysqli $mysqli, $phone, array $campos) {
    $columnasPermitidas = [
        'step', 'status', 'servicio_interes', 'presupuesto',
        'tiene_sitio_web', 'antiguedad_sitio', 'url_sitio',
        'pagespeed_score', 'clasificacion', 'cita_fecha', 'cita_hora', 'google_event_id',
    ];

    $sets = [];
    $tipos = '';
    $valores = [];
    foreach ($campos as $col => $val) {
        if (!in_array($col, $columnasPermitidas, true)) {
            continue;
        }
        $sets[] = "`$col` = ?";
        $tipos .= 's';
        $valores[] = $val;
    }
    if (!$sets) {
        return;
    }
    $tipos .= 's';
    $valores[] = $phone;

    $sql = "UPDATE leads SET " . implode(', ', $sets) . " WHERE phone = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param($tipos, ...$valores);
    $stmt->execute();
}

/**
 * Envía la lista de franjas horarias disponibles (próximos 5 días hábiles, 10:00 am y 3:00 pm)
 * y deja al lead esperando su selección en el paso 14.
 */
function enviarFranjasCita(mysqli $mysqli, $phone, $textoIntro) {
    $secciones = construirSeccionesFranjas(5);
    $enviado = enviarListaWhatsApp($phone, $textoIntro, 'Ver horarios', $secciones);
    if ($enviado['http_code'] === 200) {
        actualizarLead($mysqli, $phone, ['step' => 14]);
    }
    return $enviado;
}

/**
 * Normaliza y valida una URL escrita libremente por el usuario.
 */
function normalizarUrlSitio($texto) {
    $url = trim($texto);
    if ($url === '') {
        return null;
    }
    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    return $url;
}

function procesarCalificacion($phone, $respuestaId = null, $textoLibre = null) {
    $mysqli = obtenerConexion();

    // 1. Buscar o crear el lead en la BD
    $stmt = $mysqli->prepare("SELECT step, status FROM leads WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();

    if (!$lead) {
        $stmtInsert = $mysqli->prepare("INSERT INTO leads (phone, step, status) VALUES (?, 1, 'en_calificacion')");
        $stmtInsert->bind_param("s", $phone);
        $stmtInsert->execute();
        $step = 1;
    } else {
        $step = (int) $lead['step'];
    }

    // 2. Máquina de estados
    // El estado solo avanza si Meta ACEPTÓ el mensaje (http_code 200);
    // si el envío falla, el lead permanece en el paso actual y se reintenta en su próximo mensaje.
    switch ($step) {

        case 1:
            // Pregunta 1: Filtro de servicio vs bolsa de empleo
            $texto = "¡Hola! Gracias por escribirnos. Para atenderte mejor, ¿qué necesitas hoy?";
            $botones = [
                ['id' => 'btn_servicio', 'title' => 'Contratar Servicio'],
                ['id' => 'btn_empleo',   'title' => 'Bolsa de Trabajo'],
                ['id' => 'btn_otro',     'title' => 'Otra Consulta'],
            ];
            $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);
            if ($enviado['http_code'] === 200) {
                actualizarLead($mysqli, $phone, ['step' => 2]);
            }
            break;

        case 2:
            // Procesar respuesta a la Pregunta 1
            if ($respuestaId === 'btn_empleo' || $respuestaId === 'btn_otro') {
                $enviado = enviarTextoWhatsApp($phone, "Gracias por tu interés. En este momento no tenemos vacantes ni atención presencial disponible.");
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['status' => 'descartado', 'step' => 99]);
                }
            } elseif ($respuestaId === 'btn_servicio') {
                $texto = "¿Qué servicio te interesa?";
                $botones = [
                    ['id' => 'btn_renovar_web',   'title' => 'Renovar mi sitio web'],
                    ['id' => 'btn_otro_servicio', 'title' => 'Otro servicio'],
                ];
                $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['step' => 5]);
                }
            }
            break;

        case 5:
            // Procesar submenú de servicios
            if ($respuestaId === 'btn_renovar_web') {
                actualizarLead($mysqli, $phone, ['servicio_interes' => 'Renovación Web']);
                $texto = "Perfecto, vamos a revisar tu sitio actual. ¿Ya tienes un sitio web en funcionamiento?";
                $botones = [
                    ['id' => 'btn_si_sitio', 'title' => 'Sí, tengo uno'],
                    ['id' => 'btn_no_sitio', 'title' => 'No tengo'],
                ];
                $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['step' => 10]);
                }
            } elseif ($respuestaId === 'btn_otro_servicio') {
                actualizarLead($mysqli, $phone, ['servicio_interes' => 'Otro servicio']);
                $texto = "¿Para cuándo necesitas contratar el servicio?";
                $botones = [
                    ['id' => 'btn_urgente',   'title' => 'Esta semana'],
                    ['id' => 'btn_mes',       'title' => 'Este mes'],
                    ['id' => 'btn_solo_info', 'title' => 'Solo cotizando'],
                ];
                $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['step' => 6]);
                }
            }
            break;

        case 6:
            // Rama genérica ("otro servicio"): procesar urgencia y finalizar calificación
            if ($respuestaId === 'btn_urgente' || $respuestaId === 'btn_mes') {
                $enviado = enviarTextoWhatsApp($phone, "¡Excelente! Un asesor de nuestro equipo te contactará pronto para conocer más sobre tu proyecto.");
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['status' => 'calificado', 'step' => 99]);
                }
            } else {
                $enviado = enviarTextoWhatsApp($phone, "Entendido. Te enviamos nuestro catálogo de precios para que lo revises con calma.");
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['status' => 'descartado', 'step' => 99]);
                }
            }
            break;

        case 10:
            // Procesar "¿ya tienes un sitio web?"
            if ($respuestaId === 'btn_no_sitio') {
                actualizarLead($mysqli, $phone, ['tiene_sitio_web' => 'no', 'clasificacion' => 'sin_sitio']);
                $textoIntro = "Este flujo está pensado para renovar un sitio existente, pero con gusto te ayudamos a construir uno desde cero. Elige el horario que más te acomode para que un asesor te contacte:";
                enviarFranjasCita($mysqli, $phone, $textoIntro);
            } elseif ($respuestaId === 'btn_si_sitio') {
                actualizarLead($mysqli, $phone, ['tiene_sitio_web' => 'si']);
                $texto = "¿Hace cuánto no actualizas tu sitio web?";
                $botones = [
                    ['id' => 'btn_menos_1', 'title' => 'Menos de 1 año'],
                    ['id' => 'btn_1_2',     'title' => 'Entre 1 y 2 años'],
                    ['id' => 'btn_mas_2',   'title' => 'Más de 2 años'],
                ];
                $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['step' => 11]);
                }
            }
            break;

        case 11:
            // Procesar antigüedad del sitio y pedir la URL
            $mapaAntiguedad = [
                'btn_menos_1' => 'menos_1',
                'btn_1_2'     => '1_a_2',
                'btn_mas_2'   => 'mas_2',
            ];
            if (isset($mapaAntiguedad[$respuestaId])) {
                actualizarLead($mysqli, $phone, ['antiguedad_sitio' => $mapaAntiguedad[$respuestaId]]);
                $texto = "Compárteme la dirección (URL) de tu sitio web actual para analizarlo. Ejemplo: www.tuempresa.com";
                $enviado = enviarTextoWhatsApp($phone, $texto);
                if ($enviado['http_code'] === 200) {
                    actualizarLead($mysqli, $phone, ['step' => 12]);
                }
            }
            break;

        case 12:
            // Esperando la URL como texto libre
            if ($textoLibre === null) {
                break; // no llegó texto (ej: llegó un botón inesperado); no hacemos nada
            }

            $url = normalizarUrlSitio($textoLibre);
            if ($url === null) {
                enviarTextoWhatsApp($phone, "Esa no parece ser una URL válida. Por favor envíala así: www.tuempresa.com");
                break; // se queda en el paso 12 para reintentar
            }

            actualizarLead($mysqli, $phone, ['url_sitio' => $url]);
            enviarTextoWhatsApp($phone, "Gracias, dame un momento mientras analizo el rendimiento de tu sitio... ⏳");

            $resultado = consultarRendimientoSitio($url);

            if (!$resultado['ok']) {
                enviarTextoWhatsApp($phone, "No pude analizar tu sitio automáticamente (" . $resultado['error'] . "). No te preocupes, un asesor lo revisará manualmente contigo.");
                $textoIntro = "Elige el horario que más te acomode para que un asesor revise tu sitio:";
                enviarFranjasCita($mysqli, $phone, $textoIntro);
                break;
            }

            $score = $resultado['score'];
            $clasificacion = clasificarPorRendimiento($score);
            actualizarLead($mysqli, $phone, [
                'pagespeed_score' => (string) $score,
                'clasificacion'   => $clasificacion['clave'],
            ]);

            enviarTextoWhatsApp($phone, $clasificacion['mensaje']);

            $textoIntro = "Elige el horario que más te acomode (días hábiles, hora Colombia):";
            enviarFranjasCita($mysqli, $phone, $textoIntro);
            break;

        case 14:
            // Esperando selección de franja horaria (list_reply con id "slot_YYYY-MM-DD_HH:MM")
            if ($respuestaId === null) {
                break;
            }

            $franja = parsearFranjaSeleccionada($respuestaId);
            if ($franja === null) {
                enviarTextoWhatsApp($phone, "No reconocí esa opción. Por favor elige un horario de la lista que te enviamos.");
                break;
            }

            // Traer datos del lead para armar el evento y el mensaje de cierre
            $stmtLead = $mysqli->prepare("SELECT url_sitio, pagespeed_score, clasificacion, servicio_interes FROM leads WHERE phone = ?");
            $stmtLead->bind_param("s", $phone);
            $stmtLead->execute();
            $datosLead = $stmtLead->get_result()->fetch_assoc();

            $descripcionEvento = "Lead calificado vía WhatsApp.\nTeléfono: {$phone}\n";
            if (!empty($datosLead['url_sitio'])) {
                $descripcionEvento .= "Sitio web: {$datosLead['url_sitio']}\n";
            }
            if ($datosLead['pagespeed_score'] !== null) {
                $descripcionEvento .= "Puntaje PageSpeed (móvil): {$datosLead['pagespeed_score']}/100\n";
            }
            if (!empty($datosLead['clasificacion'])) {
                $descripcionEvento .= "Clasificación: {$datosLead['clasificacion']}\n";
            }

            $resultadoEvento = crearEventoCalendar(
                "Llamada con lead " . $phone,
                $descripcionEvento,
                $franja['inicio'],
                $franja['fin']
            );

            $fechaLegible = $franja['inicio']->format('l d/m/Y H:i');

            if ($resultadoEvento['ok']) {
                actualizarLead($mysqli, $phone, [
                    'status'          => 'calificado',
                    'step'            => 99,
                    'cita_fecha'      => $franja['inicio']->format('Y-m-d'),
                    'cita_hora'       => $franja['inicio']->format('H:i:s'),
                    'google_event_id' => $resultadoEvento['event_id'],
                ]);
                enviarTextoWhatsApp(
                    $phone,
                    "¡Listo! Tu cita quedó agendada para el *{$fechaLegible}* (hora Colombia). Un asesor te llamará en ese horario.\n\n¡Gracias por tu tiempo e interés en EISO! 🙌"
                );
            } else {
                // No se pudo crear el evento en Calendar; igual confirmamos al lead y dejamos registro para que un humano lo agende manualmente.
                actualizarLead($mysqli, $phone, [
                    'status'     => 'calificado',
                    'step'       => 99,
                    'cita_fecha' => $franja['inicio']->format('Y-m-d'),
                    'cita_hora'  => $franja['inicio']->format('H:i:s'),
                ]);
                error_log('[lead_qualifier] Error creando evento en Calendar para ' . $phone . ': ' . $resultadoEvento['error']);
                enviarTextoWhatsApp(
                    $phone,
                    "¡Listo! Tomamos nota de tu preferencia para el *{$fechaLegible}* (hora Colombia) y un asesor confirmará el espacio contigo.\n\n¡Gracias por tu tiempo e interés en EISO! 🙌"
                );
            }
            break;

        default:
            // step 99 u otro estado terminal: no se hace nada más.
            break;
    }

    $mysqli->close();
}
