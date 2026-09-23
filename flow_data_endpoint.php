<?php
/**
 * Data Endpoint del Flow "Consultar Tarifas" (Cualify EISO).
 *
 * ARQUITECTURA ASÍNCRONA:
 * Este endpoint NO realiza el análisis de PageSpeed ni crea eventos de Calendar.
 * Su única responsabilidad es:
 *   1. Descifrar la petición de Meta.
 *   2. Guardar la URL y los datos del lead en la base de datos.
 *   3. Encolar un job para que worker_pagespeed.php haga el análisis después.
 *   4. Responder INMEDIATAMENTE con la pantalla SUCCESS.
 *
 * El análisis lento (PageSpeed) y el agendamiento de la cita se realizan
 * DESPUÉS, desde worker_pagespeed.php, cuando el cron procesa el job.
 * Esto evita el timeout de 10s de WhatsApp Flows.
 *
 * Comunicación cifrada:
 *   - RSA-OAEP-SHA256 para envolver la llave AES.
 *   - AES-128-GCM para el cuerpo, IV invertido en la respuesta.
 *   - Respuesta codificada en Base64 puro (sin JSON envoltorio).
 *
 * https://developers.facebook.com/documentation/business-messaging/whatsapp/flows/guides/implementingyourflowendpoint
 *
 * NOTA: Todas las funciones de BD usan PDO (no mysqli).
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lead_qualifier.php';

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\AES;

const FLOW_AES_TAG_LENGTH = 16;

// ============================================================================
// FUNCIONES DE CIFRADO / DESCIFRADO
// ============================================================================

/**
 * Descifra el cuerpo de la petición de Meta.
 * Devuelve ['payload' => array, 'aesKey' => string, 'iv' => string] o lanza Exception.
 */
function descifrarPeticionFlow(array $body) {
    $rutaLlave  = valorEntorno('FLOW_PRIVATE_KEY_PATH');
    $passphrase = valorEntorno('FLOW_PRIVATE_KEY_PASSPHRASE') ?: false;

    if (!$rutaLlave || !is_file($rutaLlave)) {
        throw new RuntimeException('FLOW_PRIVATE_KEY_PATH no configurada o el archivo no existe.');
    }

    $rsa = PublicKeyLoader::loadPrivateKey(file_get_contents($rutaLlave), $passphrase)
        ->withPadding(RSA::ENCRYPTION_OAEP)
        ->withHash('sha256')
        ->withMGFHash('sha256');

    // 1) Desenvolver la llave AES con RSA-OAEP-SHA256
    $aesKey = $rsa->decrypt(base64_decode($body['encrypted_aes_key']));
    if ($aesKey === false || $aesKey === null) {
        throw new RuntimeException('No se pudo descifrar la llave AES con RSA-OAEP. Verifica que la llave privada coincida con la pública registrada en Meta.');
    }

    // 2) Desencriptar el payload con AES-128-GCM
    $decodedFlowData = base64_decode($body['encrypted_flow_data']);
    $cipherBody = substr($decodedFlowData, 0, -FLOW_AES_TAG_LENGTH);
    $tag        = substr($decodedFlowData, -FLOW_AES_TAG_LENGTH);
    $iv         = base64_decode($body['initial_vector']);

    $plano = openssl_decrypt($cipherBody, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plano === false) {
        try {
            $aes = new AES('gcm');
            $aes->setKey($aesKey);
            $aes->setNonce($iv);
            $aes->setTag($tag);
            $plano = $aes->decrypt($cipherBody);
        } catch (Throwable $e) {
            throw new RuntimeException('No se pudo desencriptar el payload: ' . $e->getMessage());
        }
    }

    $payload = json_decode($plano, true);
    if (!is_array($payload)) {
        throw new RuntimeException('El payload desencriptado no es JSON válido.');
    }

    return ['payload' => $payload, 'aesKey' => $aesKey, 'iv' => $iv];
}

/**
 * Cifra la respuesta con la MISMA llave AES pero el IV INVERTIDO (bit a bit),
 * y la devuelve en Base64 puro (sin JSON envoltorio).
 */
function cifrarRespuestaFlow(array $datosRespuesta, $aesKey, $iv) {
    if (strlen($aesKey) !== 16) {
        throw new RuntimeException('La llave AES no tiene 16 bytes. Longitud: ' . strlen($aesKey));
    }
    if (strlen($iv) !== 16) {
        throw new RuntimeException('El IV no tiene 16 bytes. Longitud: ' . strlen($iv));
    }

    $flippedIv = '';
    for ($i = 0; $i < strlen($iv); $i++) {
        $flippedIv .= chr(~ord($iv[$i]) & 0xFF);
    }

    $plano = json_encode($datosRespuesta, JSON_UNESCAPED_UNICODE);
    if ($plano === false) {
        throw new RuntimeException('No se pudo serializar la respuesta: ' . json_last_error_msg());
    }

    $tag = '';
    $cifrado = openssl_encrypt($plano, 'aes-128-gcm', $aesKey, OPENSSL_RAW_DATA, $flippedIv, $tag, '', FLOW_AES_TAG_LENGTH);

    if ($cifrado === false) {
        try {
            $aes = new AES('gcm');
            $aes->setKey($aesKey);
            $aes->setNonce($flippedIv);
            $aes->setTagLength(FLOW_AES_TAG_LENGTH);
            $cifrado = $aes->encrypt($plano);
            $tag = $aes->getTag();
        } catch (Throwable $e) {
            throw new RuntimeException('Fallback phpseclib también falló: ' . $e->getMessage());
        }
    }

    if ($cifrado === false || $cifrado === '') {
        throw new RuntimeException('El cifrado AES-GCM produjo un resultado vacío.');
    }

    return base64_encode($cifrado . $tag);
}

// ============================================================================
// FUNCIONES DE BASE DE DATOS (PDO)
// ============================================================================

/**
 * Asegura que exista una fila en `leads` para este teléfono.
 * (Versión PDO)
 */
function asegurarLeadFlow(PDO $pdo, $phone) {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO leads (phone, step, status) VALUES (?, 0, 'en_calificacion')
             ON DUPLICATE KEY UPDATE phone = phone"
        );
        $stmt->execute([$phone]);
    } catch (PDOException $e) {
        error_log('[asegurarLeadFlow] Error: ' . $e->getMessage());
    }
}

// ============================================================================
// MANEJO DE LA PETICIÓN
// ============================================================================

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

if (!is_array($body) || empty($body['encrypted_flow_data']) || empty($body['encrypted_aes_key']) || empty($body['initial_vector'])) {
    http_response_code(400);
    exit;
}

try {
    $descifrado = descifrarPeticionFlow($body);
} catch (Throwable $e) {
    error_log('[flow_data_endpoint] Error descifrando: ' . $e->getMessage());
    // 421: le indica a Meta que la llave pública/privada puede estar desincronizada.
    http_response_code(421);
    exit;
}

$payload = $descifrado['payload'];
$aesKey  = $descifrado['aesKey'];
$iv      = $descifrado['iv'];

// Log de diagnóstico (útil durante la puesta en marcha; puedes quitarlo después)
error_log('[flow_data_endpoint] aesKey length: ' . strlen($aesKey) . ', iv length: ' . strlen($iv));

$accion = $payload['action'] ?? null;

try {
    if ($accion === 'ping') {
        // Health check ("Realizar comprobación de estado" en el Flow Builder)
        $respuesta = ['version' => '3.0', 'data' => ['status' => 'active']];

    } elseif ($accion === 'INIT') {
        // Primera carga del Flow: no precargamos nada dinámico en JOIN_NOW.
        $respuesta = ['version' => '3.0', 'screen' => 'JOIN_NOW', 'data' => new stdClass()];

    } elseif ($accion === 'data_exchange') {
        $pantallaActual  = $payload['screen'] ?? '';
        $datosAcumulados = $payload['data'] ?? [];
        $phone           = $payload['flow_token'] ?? null; // ver nota de configuración al final

        $pdo = obtenerConexion();
        if ($phone) {
            asegurarLeadFlow($pdo, $phone);
        }

        switch ($pantallaActual) {

            case 'WEBSITE_URL':
                // 1. Normalizar URL (sin análisis)
                $url = normalizarUrlSitio($datosAcumulados['website_url'] ?? '');
                if ($url === null) {
                    $url = 'https://' . preg_replace('#^https?://#i', '', trim($datosAcumulados['website_url'] ?? ''));
                }

                // 2. Guardar URL + datos recolectados y encolar el job.
                //    Envolvemos en try/catch para que, si falla algo no crítico,
                //    el Flow pueda cerrarse igualmente con SUCCESS.
                if ($phone) {
                    try {
                        actualizarLead($pdo, $phone, [
                            'url_sitio' => $url,
                            'name'      => $datosAcumulados['name'] ?? '',
                            'email'     => $datosAcumulados['email'] ?? '',
                        ]);

                        encolarTrabajoPagespeed($pdo, $phone, $url, [
                            'email' => $datosAcumulados['email'] ?? '',
                            'name'  => $datosAcumulados['name'] ?? '',
                        ]);
                    } catch (Throwable $e) {
                        error_log('[flow_data_endpoint] Error guardando/encolando: ' . $e->getMessage());
                        // No relanzamos: el Flow debe cerrarse igual con SUCCESS.
                    }
                }

                // 3. Responder INMEDIATAMENTE con SUCCESS (sin esperar PageSpeed)
                $respuesta = [
                    'version' => '3.0',
                    'screen'  => 'SUCCESS',
                    'data'    => [
                        'name'              => $datosAcumulados['name'] ?? '',
                        'appointment_label' => 'Analizando tu sitio...',
                    ],
                ];
                break;

            default:
                // Pantalla no reconocida: devolvemos lo mismo que llegó.
                $respuesta = [
                    'version' => '3.0',
                    'screen'  => $pantallaActual,
                    'data'    => $datosAcumulados,
                ];
                break;
        }

        // PDO no requiere close() explícito. Liberamos la referencia.
        $pdo = null;

    } else {
        // Acción desconocida (BACK, error del cliente, etc.): responder neutro.
        $respuesta = [
            'version' => '3.0',
            'screen'  => $payload['screen'] ?? '',
            'data'    => $payload['data'] ?? new stdClass(),
        ];
    }

} catch (Throwable $e) {
    error_log('[flow_data_endpoint] Error de negocio: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

try {
    $respuestaCifrada = cifrarRespuestaFlow($respuesta, $aesKey, $iv);
} catch (Throwable $e) {
    error_log('[flow_data_endpoint] Error cifrando respuesta: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

header('Content-Type: text/plain');
echo $respuestaCifrada;

/**
 * ==========================================================================
 * NOTA IMPORTANTE sobre flow_token
 * ==========================================================================
 * El único dato que Meta te da para identificar la sesión es "flow_token",
 * y su valor es el que TÚ le pasaste al enviar el botón del Flow. Debe ser
 * el número de teléfono del destinatario (o un ID único que tú controles).
 *
 * Ejemplo al enviar la plantilla:
 *
 *   enviarPlantillaWhatsApp($to, "consultar_tarifas", "en", [], [
 *       [
 *           'sub_type'   => 'flow',
 *           'index'      => 0,
 *           'flow_token' => $to,   // <-- NO "unused"
 *       ]
 *   ]);
 *
 * Si envías "unused", este endpoint no sabrá a qué lead pertenece la sesión
 * y no podrá guardar la URL ni encolar el job.
 */