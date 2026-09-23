<?php
/**
 * webhook.php — Punto de entrada de WhatsApp Cloud API (Cualify EISO).
 * Ubicación: /home/muuk9x7m9to5/public_html/cualify/webhook.php
 *
 * Todas las funciones de base de datos usan PDO.
 */

// ============================================================================
// SILENCIAR WARNINGS Y NOTICES
// Meta espera respuestas limpias. Cualquier warning rompe la verificación.
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');        // NO mostrar errores en la respuesta
ini_set('log_errors', '1');            // SÍ guardarlos en el log

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead_qualifier.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// VERIFICACIÓN DEL WEBHOOK (GET)
// Meta envía un GET con hub.mode=subscribe, hub.verify_token y hub.challenge
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $modo      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    $tokenEsperado = valorEntorno('WA_VERIFY_TOKEN');

    // Log de la petición de verificación (útil para depurar)
    error_log('[webhook] VERIFICACIÓN GET — modo=' . $modo . ' token_recibido=' . substr($token, 0, 6) . '... token_esperado=' . substr((string)$tokenEsperado, 0, 6) . '...');

    if ($modo === 'subscribe' && $token !== '' && $token === $tokenEsperado) {
        // Meta espera: HTTP 200 + hub.challenge como texto plano puro
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    // Token incorrecto o modo no reconocido
    error_log('[webhook] VERIFICACIÓN FALLIDA — token no coincide o modo incorrecto');
    http_response_code(403);
    exit;
}

// ============================================================================
// RECEPCIÓN DE MENSAJES (POST)
// ============================================================================

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

// Log de diagnóstico — SOLO para POST, no para GET
error_log('[webhook] ========== WEBHOOK RECIBIDO ==========');
error_log('[webhook] Método: ' . ($_SERVER['REQUEST_METHOD'] ?? 'desconocido'));
error_log('[webhook] Body: ' . substr($rawBody, 0, 500));

if (!is_array($data) || empty($data['entry'])) {
    // Petición no reconocida: responder 200 para que Meta no reintente
    http_response_code(200);
    echo 'OK';
    exit;
}

$pdo = obtenerConexion();

foreach ($data['entry'] as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value    = $change['value'] ?? [];
        $mensajes = $value['messages'] ?? [];

        foreach ($mensajes as $msg) {
            $phone = $msg['from'] ?? null;
            $tipo  = $msg['type'] ?? null;

            error_log("[webhook] Mensaje de $phone tipo=" . ($tipo ?? 'desconocido'));

            if (!$phone) continue;

            // --------------------------------------------------------------
            // Mensajes de texto normales
            // --------------------------------------------------------------
            if ($tipo === 'text') {
                $texto = $msg['text']['body'] ?? '';
                try {
                    procesarMensajeEntrante($phone, $texto, $pdo);
                } catch (Throwable $e) {
                    error_log("[webhook] Error procesando texto de $phone: " . $e->getMessage());
                }
                continue;
            }

            // --------------------------------------------------------------
            // Mensajes interactivos
            // --------------------------------------------------------------
            if ($tipo === 'interactive') {
                $interactivo = $msg['interactive'] ?? [];
                $subtipo     = $interactivo['type'] ?? '';

                // Flow completado (nfm_reply)
                if ($subtipo === 'nfm_reply') {
                    $nfm           = $interactivo['nfm_reply'] ?? [];
                    $respuestaJson = $nfm['response_json'] ?? '{}';
                    error_log("[webhook] Flow completado por $phone: " . $respuestaJson);
                }

                // Botones (button_reply)
                if ($subtipo === 'button_reply') {
                    $texto = $interactivo['button_reply']['title'] ?? '';
                    if ($texto !== '') {
                        try {
                            procesarMensajeEntrante($phone, $texto, $pdo);
                        } catch (Throwable $e) {
                            error_log("[webhook] Error button_reply de $phone: " . $e->getMessage());
                        }
                    }
                }

                // Listas (list_reply) — SELECCIÓN DE SLOT
                if ($subtipo === 'list_reply') {
                    $slotId = $interactivo['list_reply']['id'] ?? '';
                    error_log("[webhook] list_reply de $phone — slotId=$slotId");
                    if ($slotId !== '') {
                        try {
                            procesarSeleccionSlot($phone, $slotId, $pdo);
                        } catch (Throwable $e) {
                            error_log("[webhook] Error list_reply de $phone: " . $e->getMessage());
                        }
                    }
                }

                continue;
            }

            // --------------------------------------------------------------
            // Otros tipos
            // --------------------------------------------------------------
            if (function_exists('enviarTextoWhatsApp')) {
                enviarTextoWhatsApp($phone, "Por ahora solo puedo procesar mensajes de texto. Cuéntame cómo puedo ayudarte.");
            }
        }
    }
}

$pdo = null;
http_response_code(200);
echo 'OK';
exit;