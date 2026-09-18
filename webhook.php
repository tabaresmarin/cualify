<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead_qualifier.php';

// Verificación GET de Meta
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe' && ($_GET['hub_verify_token'] ?? '') === WA_VERIFY_TOKEN) {
        http_response_code(200);
        echo $_GET['hub_challenge'];
        exit;
    }
    http_response_code(403);
    exit;
}

// Recepción POST de mensajes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');

    // Verificación opcional de firma. Si WA_APP_SECRET está configurada, Meta firma
    // cada solicitud con X-Hub-Signature-256 (HMAC-SHA256 del body usando el app secret).
    if (defined('WA_APP_SECRET')) {
        $firmaRecibida = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        $firmaEsperada = 'sha256=' . hash_hmac('sha256', $input, WA_APP_SECRET);
        if (!hash_equals($firmaEsperada, $firmaRecibida)) {
            http_response_code(401);
            exit;
        }
    }

    $data = json_decode($input, true);

    http_response_code(200); // Responder OK a Meta inmediatamente

    // Procesar todos los mensajes del payload (no solo el primero)
    $mensajes = $data['entry'][0]['changes'][0]['value']['messages'] ?? [];
    foreach ($mensajes as $msg) {
        $from = $msg['from'];
        $type = $msg['type'];

        $respuestaId = null;
        if ($type === 'interactive') {
            $respuestaId = $msg['interactive']['button_reply']['id'] ?? null;
        }

        // Ejecutar el motor de calificación en MySQL
        procesarCalificacion($from, $respuestaId);
    }
    exit;
}