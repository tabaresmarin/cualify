<?php
/**
 * apply_flow_fix.php
 *
 * Aplica la arquitectura "Flow rápido + PageSpeed fuera del Flow"
 * al repositorio Cualify.
 *
 * Ejecutar desde la raíz del repositorio:
 *   php apply_flow_fix.php
 *
 * Antes de ejecutar:
 *   git status
 *   git add -A && git commit -m "backup before flow async fix"
 */

declare(strict_types=1);

$root = __DIR__;
$flowFile = $root . '/flow.json';
$endpointFile = $root . '/flow_data_endpoint.php';
$qualifierFile = $root . '/lead_qualifier.php';

foreach ([$flowFile, $endpointFile, $qualifierFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "No existe: {$file}\n");
        exit(1);
    }
}

/* --------------------------------------------------------------------------
 * 1. flow.json
 *
 * El Flow solo llega hasta WEBSITE_URL y termina. Ya no existen dentro del
 * Flow las pantallas PAGESPEED_RESULT ni APPOINTMENT_SLOTS.
 * -------------------------------------------------------------------------- */
$flow = json_decode(file_get_contents($flowFile), true, 512, JSON_THROW_ON_ERROR);

$flow['routing_model']['WEBSITE_URL'] = ['FLOW_FINISHED'];
$flow['routing_model']['FLOW_FINISHED'] = [];

/* Elimina las pantallas lentas/antiguas. */
$flow['screens'] = array_values(array_filter(
    $flow['screens'],
    static function (array $screen): bool {
        return !in_array($screen['id'], [
            'PAGESPEED_RESULT',
            'APPOINTMENT_SLOTS',
            'SUCCESS',
        ], true);
    }
));

/* Cambia el botón de WEBSITE_URL a data_exchange, pero ya no espera PageSpeed. */
foreach ($flow['screens'] as &$screen) {
    if (($screen['id'] ?? '') !== 'WEBSITE_URL') {
        continue;
    }

    $children =& $screen['layout']['children'];
    foreach ($children as &$child) {
        if (($child['type'] ?? '') !== 'Form') {
            continue;
        }

        foreach ($child['children'] as &$item) {
            if (($item['type'] ?? '') === 'Footer') {
                $item['label'] = 'Continuar';
                $item['on-click-action'] = [
                    'name' => 'data_exchange',
                    'payload' => [
                        'name'           => '${data.name}',
                        'email'          => '${data.email}',
                        'tos_optin'      => '${data.tos_optin}',
                        'marketing_optin'=> '${data.marketing_optin}',
                        'categories'     => '${data.categories}',
                        'has_website'    => '${data.has_website}',
                        'last_update'    => '${data.last_update}',
                        'website_url'    => '${form.website_url}',
                    ],
                ];
            }
        }
        unset($item);
    }
    unset($child);
}
unset($screen);

/* Pantalla terminal nueva. */
$flow['screens'][] = [
    'id' => 'FLOW_FINISHED',
    'title' => '¡Listo!',
    'data' => [
        'name' => [
            'type' => 'string',
            '__example__' => 'Example',
        ],
        'website_url' => [
            'type' => 'string',
            '__example__' => 'https://misitio.com',
        ],
    ],
    'terminal' => true,
    'success' => true,
    'layout' => [
        'type' => 'SingleColumnLayout',
        'children' => [[
            'type' => 'Form',
            'name' => 'form',
            'children' => [
                [
                    'type' => 'TextBody',
                    'text' => '¡Recibimos los datos de tu sitio web! Cerraremos este formulario y continuaremos por WhatsApp con el análisis de rendimiento y la agenda de tu asesoría.',
                ],
                [
                    'type' => 'Footer',
                    'label' => 'Finalizar',
                    'on-click-action' => [
                        'name' => 'complete',
                        'payload' => [
                            'name' => '${data.name}',
                            'website_url' => '${data.website_url}',
                        ],
                    ],
                ],
            ],
        ]],
    ],
];

file_put_contents(
    $flowFile,
    json_encode($flow, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

/* --------------------------------------------------------------------------
 * 2. lead_qualifier.php
 *
 * Se extrae el análisis lento a una función reutilizable. El flujo normal de
 * WhatsApp y el Flow utilizan exactamente el mismo motor.
 * -------------------------------------------------------------------------- */
$qualifier = file_get_contents($qualifierFile);

if (strpos($qualifier, 'function procesarAnalisisPostFlow(') === false) {
    $marker = "function normalizarUrlSitio(\$texto) {";

    $helper = <<<'PHP'

/**
 * Ejecuta PageSpeed para un lead que ya entregó su URL desde el WhatsApp Flow.
 *
 * Esta función se ejecuta DESPUÉS de entregar la respuesta al Flow, mediante
 * fastcgi_finish_request(). Por tanto, PageSpeed no forma parte del contrato
 * síncrono del Data Endpoint de WhatsApp.
 *
 * También se usa desde el flujo conversacional normal para evitar duplicar
 * la lógica de análisis/agendamiento.
 */
function procesarAnalisisPostFlow($phone) {
    $mysqli = obtenerConexion();

    $stmt = $mysqli->prepare(
        "SELECT url_sitio, pagespeed_score, clasificacion, step
         FROM leads
         WHERE phone = ?"
    );
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $lead = $stmt->get_result()->fetch_assoc();

    if (!$lead || empty($lead['url_sitio'])) {
        $mysqli->close();
        error_log('[lead_qualifier] No hay URL para análisis post-Flow: ' . $phone);
        return;
    }

    // Evita repetir PageSpeed si Meta reintenta el data_exchange.
    if (
        $lead['pagespeed_score'] !== null &&
        $lead['pagespeed_score'] !== '' &&
        (int)$lead['step'] >= 14
    ) {
        $mysqli->close();
        return;
    }

    $url = $lead['url_sitio'];

    enviarTextoWhatsApp(
        $phone,
        "Recibí la información de tu sitio web. Ahora voy a analizar su rendimiento con PageSpeed Insights... ⏳"
    );

    $resultado = consultarRendimientoSitio($url);

    if (!$resultado['ok']) {
        enviarTextoWhatsApp(
            $phone,
            "No pude completar el análisis automático de {$url} ("
            . $resultado['error']
            . "). No te preocupes, un asesor lo revisará contigo."
        );

        enviarFranjasCita(
            $mysqli,
            $phone,
            "Elige el horario que más te acomode para que un asesor revise tu sitio:"
        );

        $mysqli->close();
        return;
    }

    $score = $resultado['score'];
    $clasificacion = clasificarPorRendimiento($score);

    actualizarLead($mysqli, $phone, [
        'pagespeed_score' => (string)$score,
        'clasificacion'   => $clasificacion['clave'],
    ]);

    enviarTextoWhatsApp($phone, $clasificacion['mensaje']);

    enviarFranjasCita(
        $mysqli,
        $phone,
        "Elige el horario que más te acomode (días hábiles, hora Colombia):"
    );

    $mysqli->close();
}

PHP;

    if (strpos($qualifier, $marker) === false) {
        throw new RuntimeException("No se encontró el punto de inserción en lead_qualifier.php");
    }

    $qualifier = str_replace($marker, $helper . "\n" . $marker, $qualifier);
}

/*
 * En el flujo conversacional tradicional, reutilizamos la misma función.
 * Sustituimos solo el bloque PageSpeed del case 12.
 */
$oldStart = "            actualizarLead(\$mysqli, \$phone, ['url_sitio' => \$url]);\n            enviarTextoWhatsApp(\$phone, \"Gracias, dame un momento mientras analizo el rendimiento de tu sitio... ⏳\");";

$oldEnd = "            enviarFranjasCita(\$mysqli, \$phone, \$textoIntro);\n            break;";

$posStart = strpos($qualifier, $oldStart);
if ($posStart !== false) {
    $posEnd = strpos($qualifier, $oldEnd, $posStart);
    if ($posEnd === false) {
        throw new RuntimeException("No se encontró el final del bloque case 12 en lead_qualifier.php");
    }
    $posEnd += strlen($oldEnd);

    $replacement = <<<'PHP'
            actualizarLead($mysqli, $phone, ['url_sitio' => $url]);
            procesarAnalisisPostFlow($phone);
            break;
PHP;

    $qualifier = substr_replace(
        $qualifier,
        $replacement,
        $posStart,
        $posEnd - $posStart
    );
}

file_put_contents($qualifierFile, $qualifier);

/* --------------------------------------------------------------------------
 * 3. flow_data_endpoint.php
 *
 * WEBSITE_URL:
 *   - valida/guarda URL
 *   - cambia el lead a step 12
 *   - responde inmediatamente FLOW_FINISHED
 *
 * Después de enviar el 200 al Flow:
 *   - fastcgi_finish_request()
 *   - procesarAnalisisPostFlow()
 *
 * PageSpeed y agenda ya no están dentro del timeout de Meta Flow.
 * -------------------------------------------------------------------------- */
$endpoint = file_get_contents($endpointFile);

$caseStart = strpos($endpoint, "            case 'WEBSITE_URL':");
$caseNext  = strpos($endpoint, "            default:", $caseStart);

if ($caseStart === false || $caseNext === false) {
    throw new RuntimeException("No se encontró el switch WEBSITE_URL/default en flow_data_endpoint.php");
}

$newCases = <<<'PHP'
            case 'WEBSITE_URL':
                $url = normalizarUrlSitio($datosAcumulados['website_url'] ?? '');

                if ($url === null) {
                    $url = 'https://' . preg_replace(
                        '#^https?://#i',
                        '',
                        trim($datosAcumulados['website_url'] ?? '')
                    );
                }

                if ($phone) {
                    actualizarLead($mysqli, $phone, [
                        'url_sitio' => $url,
                        'step'      => '12',
                        'status'    => 'en_calificacion',
                    ]);
                }

                $respuesta = [
                    'version' => '3.0',
                    'screen'  => 'FLOW_FINISHED',
                    'data'    => array_merge($datosAcumulados, [
                        'website_url' => $url,
                    ]),
                ];
                break;

PHP;

$endpoint = substr_replace(
    $endpoint,
    $newCases,
    $caseStart,
    $caseNext - $caseStart
);

/*
 * Sustituye la salida final. El Flow recibe el 200 antes de PageSpeed.
 */
$oldFinal = "salirLimpio(200, \$respuestaCifrada, 'text/plain');";

if (strpos($endpoint, "procesarAnalisisPostFlow(\$phone);") === false) {
    $newFinal = <<<'PHP'
/*
 * RESPUESTA RÁPIDA DEL FLOW
 *
 * Primero entregamos a Meta el resultado cifrado. Solamente después de que
 * Meta recibió el 200 ejecutamos PageSpeed por el canal normal de WhatsApp.
 */
if (
    $accion === 'data_exchange' &&
    ($payload['screen'] ?? '') === 'WEBSITE_URL' &&
    !empty($phone)
) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code(200);
    header('Content-Type: text/plain');
    echo $respuestaCifrada;

    if (function_exists('fastcgi_finish_request')) {
        /*
         * PHP-FPM: cierra la respuesta HTTP al cliente y permite que PHP
         * continúe ejecutando el trabajo lento.
         */
        fastcgi_finish_request();
    } else {
        /*
         * Fallback para servidores sin PHP-FPM.
         * No es tan fuerte como fastcgi_finish_request(), pero evita mantener
         * el buffer de salida abierto y permite continuar después del flush.
         */
        ignore_user_abort(true);
        @ob_flush();
        @flush();
    }

    try {
        procesarAnalisisPostFlow($phone);
    } catch (Throwable $e) {
        error_log('[flow_data_endpoint] Error en análisis post-Flow: ' . $e->getMessage());
    }

    exit;
}

salirLimpio(200, $respuestaCifrada, 'text/plain');
PHP;

    if (strpos($endpoint, $oldFinal) === false) {
        throw new RuntimeException("No se encontró la salida final de flow_data_endpoint.php");
    }

    $endpoint = str_replace($oldFinal, $newFinal, $endpoint);
}

file_put_contents($endpointFile, $endpoint);

/* --------------------------------------------------------------------------
 * 4. Validación sintáctica
 * -------------------------------------------------------------------------- */
$filesToCheck = [$endpointFile, $qualifierFile, __FILE__];

foreach ($filesToCheck as $file) {
    $cmd = 'php -l ' . escapeshellarg($file) . ' 2>&1';
    exec($cmd, $output, $code);

    echo implode("\n", $output) . "\n";

    if ($code !== 0) {
        fwrite(STDERR, "ERROR de sintaxis en {$file}\n");
        exit($code);
    }
}

echo "\nOK. Arquitectura aplicada:\n";
echo "  Flow: JOIN -> ... -> WEBSITE_URL -> FLOW_FINISHED -> complete\n";
echo "  Data Endpoint: guarda URL y responde sin PageSpeed\n";
echo "  Post-respuesta: procesarAnalisisPostFlow()\n";
echo "  PageSpeed: fuera del timeout del Flow\n";
echo "  Agenda: continúa por WhatsApp normal\n";
echo "\nRevisa el diff con:\n";
echo "  git diff -- flow.json flow_data_endpoint.php lead_qualifier.php\n";
