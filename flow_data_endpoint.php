<?php
/**
 * Data Endpoint del Flow "Consultar Tarifas" (Cualify EISO).
 *
 * Este archivo es la URL HTTPS que registraste como "punto de conexión" en el
 * Flow Builder. Meta le hace POST cada vez que una pantalla usa "data_exchange"
 * (WEBSITE_URL, PAGESPEED_RESULT, APPOINTMENT_SLOTS) y también para el "ping"
 * de comprobación de estado.
 *
 * TODA la comunicación va cifrada (RSA-OAEP-SHA256 para envolver la llave AES,
 * AES-128-GCM para el cuerpo, IV invertido en la respuesta). Esto es requisito
 * de Meta, no es opcional: https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/implementingyourflowendpoint
 *
 * ==========================================================================
 * INSTALACIÓN
 * ==========================================================================
 * 1) Instala phpseclib3 (PHP nativo NO soporta RSA-OAEP con SHA-256, solo SHA-1):
 *      composer require phpseclib/phpseclib:~3.0
 *    Si no puedes usar Packagist desde tu servidor, descarga el .zip del release
 *    desde https://github.com/phpseclib/phpseclib/releases y ajusta el require
 *    de abajo para apuntar a su autoloader.
 *
 * 2) Agrega a tu .env:
 *      FLOW_PRIVATE_KEY_PATH=/ruta/absoluta/secrets/flow_private.pem
 *      FLOW_PRIVATE_KEY_PASSPHRASE=   (déjalo vacío si tu llave no tiene passphrase)
 *
 * 3) En el Flow Builder, el "punto de conexión" debe apuntar a esta URL, ej:
 *      https://eiso.com.co/cualify/flow_data_endpoint.php
 *
 * 4) IMPORTANTE: cuando envíes la plantilla con el botón del Flow (whatsapp_api.php),
 *    el "flow_token" debe ser el número de teléfono del destinatario (o un ID único
 *    que tú controles), NO "unused". Ese es el ÚNICO dato que este endpoint recibe
 *    para saber a quién pertenece la sesión — ver la nota al final de este archivo.
 */

// ----------------------------------------------------------------------------
// BLINDAJE DE SALIDA: la respuesta a Meta debe ser EXACTAMENTE la cadena base64,
// ni un byte más. Un BOM, un salto de línea sobrante de algún require, o un
// warning de PHP que se imprima aunque display_errors esté "off" en algunos
// hostings, rompe silenciosamente el cuerpo. Capturamos TODA la salida en un
// buffer y solo dejamos pasar lo que nosotros controlamos explícitamente.
// ----------------------------------------------------------------------------
ob_start();
ini_set('display_errors', '0'); // no imprimir errores al cuerpo de la respuesta
error_reporting(E_ALL);         // pero sí seguir registrándolos en el log

require_once __DIR__ . '/vendor/autoload.php'; // autoloader de Composer (phpseclib3)
require_once __DIR__ . '/lead_qualifier.php';  // trae config.php, whatsapp_api.php,
                                                // pagespeed_api.php, google_calendar_api.php,
                                                // appointment_slots.php y actualizarLead()/obtenerConexion()

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;

const FLOW_AES_TAG_LENGTH = 16;

/**
 * Descifra el cuerpo de la petición de Meta.
 * Devuelve ['payload' => array, 'aesKey' => string, 'iv' => string] o lanza Exception.
 */
function descifrarPeticionFlow(array $body) {
    $rutaLlave   = valorEntorno('FLOW_PRIVATE_KEY_PATH');
    $passphrase  = valorEntorno('FLOW_PRIVATE_KEY_PASSPHRASE') ?: false;

    if (!$rutaLlave || !is_file($rutaLlave)) {
        throw new RuntimeException('FLOW_PRIVATE_KEY_PATH no configurada o el archivo no existe.');
    }

    $rsa = PublicKeyLoader::loadPrivateKey(file_get_contents($rutaLlave), $passphrase)
        ->withPadding(RSA::ENCRYPTION_OAEP)
        ->withHash('sha256')
        ->withMGFHash('sha256');

    // 1) Desenvolver la llave AES (128 bits) con RSA-OAEP-SHA256
    $aesKey = $rsa->decrypt(base64_decode($body['encrypted_aes_key']));

    // 2) Desencriptar el payload con AES-128-GCM
    //    Los últimos 16 bytes del blob son el authentication tag de GCM.
    $decodedFlowData = base64_decode($body['encrypted_flow_data']);
    $cipherBody = substr($decodedFlowData, 0, -FLOW_AES_TAG_LENGTH);
    $tag        = substr($decodedFlowData, -FLOW_AES_TAG_LENGTH);
    $iv         = base64_decode($body['initial_vector']);

    $plano = openssl_decrypt($cipherBody, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plano === false) {
        throw new RuntimeException('No se pudo desencriptar el payload (AES-GCM falló).');
    }

    $payload = json_decode($plano, true);
    if (!is_array($payload)) {
        throw new RuntimeException('El payload desencriptado no es JSON válido.');
    }

    return ['payload' => $payload, 'aesKey' => $aesKey, 'iv' => $iv];
}

/**
 * Cifra la respuesta con la MISMA llave AES pero el IV INVERTIDO (bit a bit),
 * tal como exige el protocolo, y la devuelve en base64 (string plano, sin JSON alrededor).
 */
function cifrarRespuestaFlow(array $datosRespuesta, $aesKey, $iv) {
    $flippedIv = '';
    for ($i = 0; $i < strlen($iv); $i++) {
        $flippedIv .= chr(~ord($iv[$i]) & 0xFF);
    }

    $plano = json_encode($datosRespuesta, JSON_UNESCAPED_UNICODE);
    $tag = '';
    $cifrado = openssl_encrypt($plano, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $flippedIv, $tag, '', FLOW_AES_TAG_LENGTH);

    return base64_encode($cifrado . $tag);
}

/**
 * Asegura que exista una fila en `leads` para este teléfono (el Flow puede ser
 * el primer contacto, a diferencia del flujo conversacional que crea el lead en step 1).
 */
function asegurarLeadFlow(mysqli $mysqli, $phone) {
    $stmt = $mysqli->prepare("INSERT INTO leads (phone, step, status) VALUES (?, 0, 'en_calificacion') ON DUPLICATE KEY UPDATE phone = phone");
    $stmt->bind_param('s', $phone);
    $stmt->execute();
}

/**
 * Único punto de salida del script: descarta TODO lo que haya quedado en el
 * buffer de salida (warnings, BOM, espacios de algún require, etc.) y envía
 * exactamente el cuerpo que nosotros construimos, nada más.
 */
function salirLimpio($statusCode, $body = '', $contentType = null) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if ($contentType) {
        header('Content-Type: ' . $contentType);
    }
    if ($body !== '') {
        echo $body;
    }
    exit;
}

// ============================================================================
// MANEJO DE LA PETICIÓN
// ============================================================================

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!is_array($body) || empty($body['encrypted_flow_data']) || empty($body['encrypted_aes_key']) || empty($body['initial_vector'])) {
    // Petición mal formada: no es un sobre cifrado válido de Meta.
    salirLimpio(400);
}

try {
    $descifrado = descifrarPeticionFlow($body);
} catch (Throwable $e) {
    error_log('[flow_data_endpoint] Error descifrando: ' . $e->getMessage());
    // 421: le indica a Meta que la llave pública/privada puede estar desincronizada.
    salirLimpio(421);
}

$payload = $descifrado['payload'];
$aesKey  = $descifrado['aesKey'];
$iv      = $descifrado['iv'];

$accion = $payload['action'] ?? null;

try {
    if ($accion === 'ping') {
        // Health check ("Realizar comprobación de estado" en el Flow Builder)
        $respuesta = ['version' => '3.0', 'data' => ['status' => 'active']];

    } elseif ($accion === 'INIT') {
        // Primera carga del Flow: no necesitamos precargar nada dinámico en JOIN_NOW.
        $respuesta = ['version' => '3.0', 'screen' => 'JOIN_NOW', 'data' => new stdClass()];

    } elseif ($accion === 'data_exchange') {
        $pantallaActual = $payload['screen'] ?? '';
        $datosAcumulados = $payload['data'] ?? [];
        $phone = $payload['flow_token'] ?? null; // ver nota de configuración al final del archivo

        $mysqli = obtenerConexion();
        if ($phone) {
            asegurarLeadFlow($mysqli, $phone);
        }

        switch ($pantallaActual) {

            case 'WEBSITE_URL':
                $url = normalizarUrlSitio($datosAcumulados['website_url'] ?? '');
                if ($url === null) {
                    // URL inválida: la reenviamos a la misma pantalla (el Flow no tiene
                    // un campo de error dedicado aquí, así que forzamos https:// y seguimos
                    // con el mejor intento; si PageSpeed falla, se informa en el siguiente paso).
                    $url = 'https://' . preg_replace('#^https?://#i', '', trim($datosAcumulados['website_url'] ?? ''));
                }

                $resultado = consultarRendimientoSitio($url);
                if ($resultado['ok']) {
                    $score = $resultado['score'];
                    $clasif = clasificarPorRendimiento($score);
                    $scoreBucket = $clasif['clave'];
                    $scoreMessage = "Analizamos {$url} con PageSpeed Insights.\n\n"
                        . "Puntaje de rendimiento: {$score}/100\n\n"
                        . $clasif['mensaje'];
                } else {
                    $score = 0;
                    $scoreBucket = 'error';
                    $scoreMessage = "Analizamos {$url}, pero no pudimos completar el análisis automático ("
                        . $resultado['error'] . "). No te preocupes, un asesor lo revisará contigo.";
                }

                if ($phone) {
                    actualizarLead($mysqli, $phone, [
                        'url_sitio'       => $url,
                        'pagespeed_score' => (string) $score,
                        'clasificacion'   => $scoreBucket,
                    ]);
                }

                $respuesta = [
                    'version' => '3.0',
                    'screen'  => 'PAGESPEED_RESULT',
                    'data'    => array_merge($datosAcumulados, [
                        'website_url'           => $url,
                        'performance_score'     => $score,
                        'score_bucket'          => $scoreBucket,
                        'score_message'         => $scoreMessage,
                        // WhatsApp Flows NO permite mezclar texto literal con una variable
                        // (ej. "Puntaje: ${data.x}/100" se muestra literal, sin interpolar).
                        // Por eso armamos la frase completa aquí y la pantalla solo referencia
                        // el campo entero: "${data.website_analyzed_text}".
                        'website_analyzed_text' => "Analizamos {$url} con PageSpeed Insights.",
                        'score_heading_text'    => "Puntaje de rendimiento: {$score}/100",
                    ]),
                ];
                break;

            case 'PAGESPEED_RESULT':
                $slots = construirSlotsPlanoFlow(5);
                $respuesta = [
                    'version' => '3.0',
                    'screen'  => 'APPOINTMENT_SLOTS',
                    'data'    => array_merge($datosAcumulados, ['slots' => $slots]),
                ];
                break;

            case 'APPOINTMENT_SLOTS':
                $slotId = $datosAcumulados['appointment_slot'] ?? '';
                $franja = parsearSlotFlow($slotId);

                if ($franja === null) {
                    // Selección inválida: reenviamos la misma pantalla con la lista otra vez.
                    $respuesta = [
                        'version' => '3.0',
                        'screen'  => 'APPOINTMENT_SLOTS',
                        'data'    => array_merge($datosAcumulados, ['slots' => construirSlotsPlanoFlow(5)]),
                    ];
                    break;
                }

                $advisorEmail = valorEntorno('GOOGLE_CALENDAR_ID');
                $descripcionEvento = "Lead calificado vía WhatsApp Flow.\n"
                    . "Teléfono: " . ($phone ?? 'desconocido') . "\n"
                    . "Nombre: " . ($datosAcumulados['name'] ?? '') . "\n"
                    . "Email: " . ($datosAcumulados['email'] ?? '') . "\n"
                    . "Sitio web: " . ($datosAcumulados['website_url'] ?? '') . "\n"
                    . "Puntaje PageSpeed: " . ($datosAcumulados['performance_score'] ?? '') . "/100\n"
                    . "Clasificación: " . ($datosAcumulados['score_bucket'] ?? '') . "\n";

                $resultadoEvento = crearEventoCalendar(
                    'Llamada con lead ' . ($phone ?? ''),
                    $descripcionEvento,
                    $franja['inicio'],
                    $franja['fin'],
                    $datosAcumulados['email'] ?? null
                );

                if (!$resultadoEvento['ok']) {
                    error_log('[flow_data_endpoint] Error creando evento en Calendar: ' . $resultadoEvento['error']);
                }

                if ($phone) {
                    actualizarLead($mysqli, $phone, array_filter([
                        'status'          => 'calificado',
                        'step'            => '99',
                        'cita_fecha'      => $franja['inicio']->format('Y-m-d'),
                        'cita_hora'       => $franja['inicio']->format('H:i:s'),
                        'google_event_id' => $resultadoEvento['ok'] ? $resultadoEvento['event_id'] : null,
                    ]));
                }

                $respuesta = [
                    'version' => '3.0',
                    'screen'  => 'CONFIRMACION',
                    'data'    => [
                        'name'              => $datosAcumulados['name'] ?? '',
                        'appointment_slot'  => $slotId,
                        'appointment_label' => formatearFranjaLegible($franja['inicio']),
                        'advisor_email'     => $advisorEmail,
                    ],
                ];
                break;

            default:
                // Pantalla no reconocida: devolvemos lo mismo que llegó, sin cambios.
                $respuesta = ['version' => '3.0', 'screen' => $pantallaActual, 'data' => $datosAcumulados];
                break;
        }

        $mysqli->close();

    } else {
        // Acción desconocida (BACK, error del cliente, etc.): responder neutro.
        $respuesta = ['version' => '3.0', 'screen' => $payload['screen'] ?? '', 'data' => $payload['data'] ?? new stdClass()];
    }

} catch (Throwable $e) {
    error_log('[flow_data_endpoint] Error de negocio: ' . $e->getMessage());
    salirLimpio(500);
}

$respuestaCifrada = cifrarRespuestaFlow($respuesta, $aesKey, $iv);

error_log('[flow_data_endpoint] OK - action=' . ($accion ?? '?') . ' screen_in=' . ($payload['screen'] ?? '?') . ' screen_out=' . ($respuesta['screen'] ?? '?') . ' bytes=' . strlen($respuestaCifrada));

salirLimpio(200, $respuestaCifrada, 'text/plain');

/**
 * ==========================================================================
 * NOTA IMPORTANTE sobre flow_token
 * ==========================================================================
 * El único dato que Meta te da para identificar la sesión es "flow_token",
 * y su valor es el que TÚ le pasaste al enviar el botón del Flow. Ahora mismo,
 * en test_send.php, se está enviando como 'unused'. Para que este endpoint
 * pueda guardar el lead correcto y armar bien la cita en Calendar, cambia eso
 * por el número de teléfono del destinatario, por ejemplo:
 *
 *   enviarPlantillaWhatsApp($to, "consultar_tarifas", "en", [], [
 *       [
 *           'sub_type'   => 'flow',
 *           'index'      => 0,
 *           'flow_token' => $to,   // <-- antes decía 'unused'
 *       ]
 *   ]);
 */