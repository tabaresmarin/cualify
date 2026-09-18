<?php
require_once __DIR__ . '/config.php';

/**
 * Envía un payload arbitrario a la API de WhatsApp.
 * Devuelve ['http_code', 'respuesta', 'error'].
 */
function enviarMensajeWhatsApp($to, $payload) {
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
 *
 * @param string $to            Número destino
 * @param string $textoMensaje  Cuerpo del mensaje
 * @param string $textoBoton    Texto del botón que abre la lista (máx. 20 caracteres)
 * @param array  $secciones     [
 *                                 [
 *                                   'title' => 'Lunes 22 sep', // máx 24 caracteres
 *                                   'rows'  => [
 *                                     ['id' => 'slot_2026-09-22_10:00', 'title' => '10:00 am', 'description' => ''],
 *                                   ]
 *                                 ],
 *                                 ...
 *                               ]
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
 *
 * @param string $to               Número destino
 * @param string $nombrePlantilla  Nombre exacto de la plantilla en Meta
 * @param string $codigoIdioma     Código de idioma tal como está en la plantilla (ej: "en", "es", "es_CO", "en_US")
 * @param array  $parametros       Parámetros de texto para el BODY (en orden, {{1}}, {{2}}, ...)
 * @param array  $botones          Opcional. Componentes de botón, uno por botón dinámico que tenga la plantilla.
 *
 *                                 Para botones 'url' / 'copy_code' (type=text):
 *                                 [
 *                                   [
 *                                     'sub_type'   => 'url',
 *                                     'index'      => 0,
 *                                     'parametros' => ['valor-dinamico']
 *                                   ]
 *                                 ]
 *
 *                                 Para botones de tipo 'flow' (SIEMPRE deben declararse,
 *                                 tengan o no datos dinámicos), usan type=action:
 *                                 [
 *                                   [
 *                                     'sub_type'   => 'flow',
 *                                     'index'      => 0,
 *                                     'flow_token' => 'unused', // o un token único de sesión que generes tú
 *                                     'flow_action_data' => []  // opcional: datos iniciales para la primera pantalla
 *                                   ]
 *                                 ]
 */
function enviarPlantillaWhatsApp($to, $nombrePlantilla, $codigoIdioma = "es", $parametros = [], $botones = []) {
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
    // (URL con variable, Flow, Copy Code, etc.). Cada botón requiere su propio
    // componente con 'sub_type' e 'index' (posición del botón, empieza en 0).
    foreach ($botones as $btn) {
        $buttonComponent = [
            "type"     => "button",
            "sub_type" => $btn['sub_type'],
            "index"    => (string)$btn['index'],
        ];

        if ($btn['sub_type'] === 'flow') {
            // Los botones de tipo Flow SIEMPRE requieren este componente,
            // incluso si el Flow no necesita datos iniciales.
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
            // Botones 'url' o 'copy_code' con valor dinámico: type "text"
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

    return enviarMensajeWhatsApp($to, [
        "type"     => "template",
        "template" => $templateData,
    ]);
}