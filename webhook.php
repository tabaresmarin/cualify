<?php
/**
 * webhook.php — Punto de entrada de WhatsApp Cloud API (Cualify EISO).
 *
 * ARQUITECTURA ASÍNCRONA:
 *   - Este archivo NO procesa la lógica de negocio (evita timeouts de Meta).
 *   - Solo verifica la petición, encola los mensajes en `jobs_queue` y
 *     responde 200 OK inmediatamente.
 *   - worker_ia.php (cron) toma los jobs y los procesa con IA + envía por WhatsApp.
 *
 * Ubicación: /home/muuk9x7m9to5/public_html/cualify/webhook.php
 */

// ============================================================================
// SILENCIAR OUTPUT — Meta espera respuestas limpias
// ============================================================================
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead_qualifier.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// VERIFICACIÓN DEL WEBHOOK (GET)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $modo      = $_GET['hub_mode'] ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge'] ?? '';

    $tokenEsperado = valorEntorno('WHATSAPP_VERIFY_TOKEN');

    error_log('[webhook] VERIFICACIÓN GET — modo=' . $modo
        . ' token_recibido=' . substr($token, 0, 6) . '...'
        . ' token_esperado=' . substr((string)$tokenEsperado, 0, 6) . '...');

    if ($modo === 'subscribe' && $token !== '' && $token === $tokenEsperado) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    error_log('[webhook] VERIFICACIÓN FALLIDA');
    http_response_code(403);
    exit;
}

// ============================================================================
// RECEPCIÓN DE MENSAJES (POST)
// ============================================================================
$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

error_log('[webhook] ========== WEBHOOK RECIBIDO ==========');
error_log('[webhook] Método: ' . ($_SERVER['REQUEST_METHOD'] ?? 'desconocido'));
error_log('[webhook] Body: ' . substr($rawBody, 0, 500));

if (!is_array($data) || empty($data['entry'])) {
    http_response_code(200);
    echo 'OK';
    exit;
}

$pdo = obtenerConexion();

foreach ($data['entry'] as $entry) {
    foreach (($entry['changes'] ?? []) as $change) {
        $value = $change['value'] ?? [];

        // ----------------------------------------------------------------
        // Status updates (sent, delivered, read) — solo loguear
        // ----------------------------------------------------------------
        if (!empty($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                error_log("[webhook] Status: " . ($status['status'] ?? '?')
                    . " → " . ($status['recipient_id'] ?? '?'));
            }
        }

        // ----------------------------------------------------------------
        // Mensajes entrantes
        // ----------------------------------------------------------------
        foreach (($value['messages'] ?? []) as $msg) {
            $phone = $msg['from'] ?? null;
            $tipo  = $msg['type'] ?? null;

            if (!$phone) continue;

            error_log("[webhook] Mensaje de $phone tipo=" . ($tipo ?? 'desconocido'));

            // ------------------------------------------------------------
            // Mensajes de texto → rate limit + encolar
            // ------------------------------------------------------------

            if ($tipo === 'text') {
                $texto     = $msg['text']['body'] ?? '';
                $messageId = $msg['id'] ?? '';

                if ($texto === '') continue;

                if (!puedeUsarIA($phone, $pdo)) {
                    error_log("[webhook] Rate limit alcanzado para $phone");
                    enviarTextoWhatsApp($phone, "Estás enviando mensajes muy rápido. Por favor, espera un momento. 🙏");
                    continue;
                }

                $jobId = encolarMensajeIA($pdo, $phone, [
                    'tipo'       => 'text',
                    'texto'      => $texto,
                    'message_id' => $messageId,
                ]);
                error_log("[webhook] Encolado job ia_message #$jobId para $phone");

                // ⭐ DISPARAR WORKER INMEDIATAMENTE
                dispararWorker('ia');
                continue;
            }

            // ------------------------------------------------------------
            // Mensajes interactivos
            // ------------------------------------------------------------
            if ($tipo === 'interactive') {
                $interactivo = $msg['interactive'] ?? [];
                $subtipo     = $interactivo['type'] ?? '';

                // Flow completado → ya fue encolado por flow_data_endpoint.php
                if ($subtipo === 'nfm_reply') {
                    $respuestaJson = $interactivo['nfm_reply']['response_json'] ?? '{}';
                    error_log("[webhook] Flow completado por $phone: " . substr($respuestaJson, 0, 200));
                    continue;
                }

                // Selección de slot (list_reply) → encolar con slot_id
                if ($subtipo === 'list_reply') {
                    $slotId    = $interactivo['list_reply']['id'] ?? '';
                    $messageId = $msg['id'] ?? '';
                    if ($slotId !== '') {
                        $jobId = encolarMensajeIA($pdo, $phone, [
                            'tipo'        => 'slot_selection',
                            'slot_id'     => $slotId,
                            'message_id'  => $messageId,
                        ]);
                        error_log("[webhook] Encolado slot_selection #$jobId para $phone (slot=$slotId)");
                    }
                    continue;
                }

                // Botón (button_reply) → encolar como texto
                if ($subtipo === 'button_reply') {
                    $texto = $interactivo['button_reply']['title'] ?? '';
                    if ($texto !== '') {
                        $jobId = encolarMensajeIA($pdo, $phone, [
                            'tipo'  => 'text',
                            'texto' => $texto,
                        ]);
                        error_log("[webhook] Encolado button_reply #$jobId para $phone");
                    }
                    continue;
                }

                continue;
            }

            // ------------------------------------------------------------
            // Otros tipos (imagen, audio, video, sticker, etc.)
            // ------------------------------------------------------------
            error_log("[webhook] Tipo no soportado: $tipo");
            // Responder rápido sin encolar (raro y no crítico)
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

