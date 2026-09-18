<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_api.php';

function obtenerConexion() {
    return new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
}

function procesarCalificacion($phone, $respuestaId = null) {
    $mysqli = obtenerConexion();
    
    // 1. Buscar o crear el lead en la BD
    $stmt = $mysqli->prepare("SELECT step, status FROM leads WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    $lead = $result->fetch_assoc();

    if (!$lead) {
        $stmtInsert = $mysqli->prepare("INSERT INTO leads (phone, step, status) VALUES (?, 1, 'en_calificacion')");
        $stmtInsert->bind_param("s", $phone);
        $stmtInsert->execute();
        $step = 1;
    } else {
        $step = $lead['step'];
    }

    // 2. Máquina de Estados (Flujo de Calificación)
    // El estado solo avanza si Meta ACEPTÓ el mensaje (http_code 200);
    // si el envío falla, el lead permanece en el paso actual y se reintenta en su próximo mensaje.
    switch ($step) {
        case 1:
            // Pregunta 1: Filtro de servicio vs bolsa de empleo
            $texto = "¡Hola! Gracias por escribirnos. Para atenderte mejor, ¿qué necesitas hoy?";
            $botones = [
                ['id' => 'btn_servicio', 'title' => 'Contratar Servicio'],
                ['id' => 'btn_empleo', 'title' => 'Bolsa de Trabajo'],
                ['id' => 'btn_otro', 'title' => 'Otra Consulta']
            ];
            $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);

            if ($enviado['http_code'] === 200) {
                // Avanzar al paso 2
                $stmtUp = $mysqli->prepare("UPDATE leads SET step = 2 WHERE phone = ?");
                $stmtUp->bind_param("s", $phone);
                $stmtUp->execute();
            }
            break;

        case 2:
            // Procesar respuesta a la Pregunta 1
            if ($respuestaId === 'btn_empleo' || $respuestaId === 'btn_otro') {
                // Descartar lead
                $enviado = enviarTextoWhatsApp($phone, "Gracias por tu interés. En este momento no tenemos vacantes ni atención presencial disponible.");

                if ($enviado['http_code'] === 200) {
                    $stmtUp = $mysqli->prepare("UPDATE leads SET status = 'descartado', step = 99 WHERE phone = ?");
                    $stmtUp->bind_param("s", $phone);
                    $stmtUp->execute();
                }
            } else {
                // El lead está interesado en contratar -> Enviar Pregunta 2 (Urgencia)
                $texto = "¿Para cuándo necesitas contratar el servicio?";
                $botones = [
                    ['id' => 'btn_urgente', 'title' => 'Esta semana'],
                    ['id' => 'btn_mes', 'title' => 'Este mes'],
                    ['id' => 'btn_solo_info', 'title' => 'Solo cotizando']
                ];
                $enviado = enviarBotonesWhatsApp($phone, $texto, $botones);

                if ($enviado['http_code'] === 200) {
                    $stmtUp = $mysqli->prepare("UPDATE leads SET servicio_interes = 'Contratación', step = 3 WHERE phone = ?");
                    $stmtUp->bind_param("s", $phone);
                    $stmtUp->execute();
                }
            }
            break;

        case 3:
            // Procesar respuesta a la Pregunta 2 y finalizar calificación
            if ($respuestaId === 'btn_urgente' || $respuestaId === 'btn_mes') {
                $enviado = enviarTextoWhatsApp($phone, "¡Excelente! Un asesor de nuestro equipo te contactará de inmediato. Puedes agendar una llamada aquí: https://tu-sitio.com/agendar");

                if ($enviado['http_code'] === 200) {
                    $stmtUp = $mysqli->prepare("UPDATE leads SET status = 'calificado', step = 4 WHERE phone = ?");
                    $stmtUp->bind_param("s", $phone);
                    $stmtUp->execute();
                }
            } else {
                $enviado = enviarTextoWhatsApp($phone, "Entendido. Te enviamos nuestro catálogo de precios para que lo revises con calma.");

                if ($enviado['http_code'] === 200) {
                    $stmtUp = $mysqli->prepare("UPDATE leads SET status = 'descartado', step = 99 WHERE phone = ?");
                    $stmtUp->bind_param("s", $phone);
                    $stmtUp->execute();
                }
            }
            break;
    }

    $mysqli->close();
}