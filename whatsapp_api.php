<?php
require_once __DIR__ . '/config.php';

/**
 * Envía un payload arbitrario a la API de WhatsApp.
 * Devuelve ['http_code', 'respuesta', 'error'].
 *
 * MEJORA: Si $payload es un string, lo envuelve automáticamente en un
 * payload de tipo 'text'. Esto permite compatibilidad con código legado
 * que llame a esta función pasándole directamente el mensaje de texto.
 */
function enviarMensajeWhatsApp($to, $payload) {
    // Compatibilidad: si recibe un string, lo tratamos como mensaje de texto
    if (is_string($payload)) {
        $payload = [
            'type' => 'text',
            'text' => ['body' => $payload],
        ];
    }

    // Validación estricta: debe ser array a partir de aquí
    if (!is_array($payload)) {
        throw new InvalidArgumentException(
            'enviarMensajeWhatsApp: $payload debe ser array o string, se recibió ' . gettype($payload)
        );
    }

    $url = 'https://graph.facebook.com/v19.0/' . WA_PHONE_NUMBER_ID . '/messages';

    $payload = array_merge([
        'messaging_product' => 'whatsapp',
        'recipient_type'    => 'individual',
        'to'                => $to,
    ], $payload);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WA_ACCESS_TOKEN,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $respuesta = curl_exec($ch);
    $resultado = [
        'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'respuesta' => $respuesta,
        'error'     => curl_error($ch),
    ];
    curl_close($ch);
    return $resultado;
}

/**
 * Envía un mensaje con botones de respuesta rápida (reply buttons).
 */
function enviarBotonesWhatsApp($to, $textoMensaje, $botones) {
    $buttonsPayload = [];
    foreach ($botones as $btn) {
        $buttonsPayload[] = [
            'type'   => 'reply',
            'reply'  => [
                'id'    => $btn['id'],
                'title' => $btn['title'],
            ],
        ];
    }

    return enviarMensajeWhatsApp($to, [
        'type'        => 'interactive',
        'interactive' => [
            'type'   => 'button',
            'body'   => ['text' => $textoMensaje],
            'action' => ['buttons' => $buttonsPayload],
        ],
    ]);
}

/**
 * Envía un mensaje de lista interactiva (hasta 10 filas en total, repartidas en secciones).
 * Útil para ofrecer varias opciones que no caben en botones de respuesta rápida (máx. 3).
 */
function enviarListaWhatsApp($to, $textoMensaje, $textoBoton, $secciones) {
    return enviarMensajeWhatsApp($to, [
        'type'        => 'interactive',
        'interactive' => [
            'type'   => 'list',
            'body'   => ['text' => $textoMensaje],
            'action' => [
                'button'   => $textoBoton,
                'sections' => $secciones,
            ],
        ],
    ]);
}

/**
 * Envía un mensaje de texto simple.
 */
function enviarTextoWhatsApp($to, $textoMensaje) {
    return enviarMensajeWhatsApp($to, [
        'type' => 'text',
        'text' => ['body' => $textoMensaje],
    ]);
}

/**
 * Envía un mensaje de plantilla aprobada.
 * Obligatorio cuando el destino NO escribió al bot en las últimas 24 h.
 */
function enviarPlantillaWhatsApp($to, $nombrePlantilla, $codigoIdioma = "es", $parametros = [], $botones = []) {
    $url = "https://graph.facebook.com/v19.0/" . WA_PHONE_NUMBER_ID . "/messages";

    $templateData = [
        "name" => $nombrePlantilla,
        "language" => [
            "code" => $codigoIdioma
        ]
    ];

    $components = [];

    // Componente BODY: solo si hay parámetros de texto para el cuerpo
    if (!empty($parametros)) {
        $parameterObjects = [];
        foreach ($parametros as $val) {
            $parameterObjects[] = [
                "type" => "text",
                "text" => (string)$val
            ];
        }

        $components[] = [
            "type" => "body",
            "parameters" => $parameterObjects
        ];
    }

    // Componente(s) BUTTON: obligatorios si la plantilla tiene botones dinámicos
    foreach ($botones as $btn) {
        $buttonComponent = [
            "type"     => "button",
            "sub_type" => $btn['sub_type'],
            "index"    => (string)$btn['index'],
        ];

        if ($btn['sub_type'] === 'flow') {
            $action = [
                "flow_token" => $btn['flow_token'] ?? 'unused',
            ];
            if (!empty($btn['flow_action_data'])) {
                $action['flow_action_data'] = $btn['flow_action_data'];
            }

            $buttonComponent["parameters"] = [
                [
                    "type"   => "action",
                    "action" => $action,
                ]
            ];
        } elseif (!empty($btn['parametros'])) {
            $buttonParams = [];
            foreach ($btn['parametros'] as $val) {
                $buttonParams[] = [
                    "type" => "text",
                    "text" => (string)$val
                ];
            }
            $buttonComponent["parameters"] = $buttonParams;
        }

        $components[] = $buttonComponent;
    }

    if (!empty($components)) {
        $templateData["components"] = $components;
    }

    $payload = [
        "messaging_product" => "whatsapp",
        "recipient_type"    => "individual",
        "to"                => $to,
        "type"              => "template",
        "template"          => $templateData
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . WA_ACCESS_TOKEN,
            'Content-Type: application/json'
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true
    ]);

    $respuesta = curl_exec($ch);
    curl_close($ch);

    return $respuesta;
}