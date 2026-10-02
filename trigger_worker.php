<?php
/**
 * trigger_worker.php — Dispara un worker internamente vía HTTP.
 *
 * Uso: /trigger_worker.php?worker=ia&token=SECRET
 *
 * Responde inmediatamente (202 Accepted) y ejecuta el worker en background.
 * Solo accesible desde localhost o con el token correcto.
 */

// ============================================================================
// SEGURIDAD: solo localhost o con token válido
// ============================================================================
require_once __DIR__ . '/config.php';

$tokenEsperado = valorEntorno('WORKER_TRIGGER_TOKEN');
$tokenRecibido = $_GET['token'] ?? '';
$ipRemota      = $_SERVER['REMOTE_ADDR'] ?? '';

$esLocalhost = in_array($ipRemota, ['127.0.0.1', '::1'], true);
$tokenValido = !empty($tokenEsperado) && hash_equals($tokenEsperado, $tokenRecibido);

if (!$esLocalhost && !$tokenValido) {
    http_response_code(403);
    exit('Acceso denegado');
}

// ============================================================================
// DETERMINAR QUÉ WORKER EJECUTAR
// ============================================================================
$worker = $_GET['worker'] ?? 'ia';

$comandos = [
    'ia'        => '/home/muuk9x7m9to5/public_html/cualify/worker_ia.php',
    'pagespeed' => '/home/muuk9x7m9to5/public_html/cualify/worker_pagespeed.php',
];

if (!isset($comandos[$worker])) {
    http_response_code(400);
    exit('Worker desconocido');
}

// ============================================================================
// RESPONDER INMEDIATAMENTE (antes de ejecutar)
// ============================================================================
http_response_code(202);
header('Content-Type: text/plain');
echo "Worker $worker encolado";

// Cerrar la conexión con el cliente (webhook.php)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    // Para LiteSpeed / Apache, forzar el cierre
    if (ob_get_level() > 0) ob_end_flush();
    flush();
}

// ============================================================================
// EJECUTAR EL WORKER EN BACKGROUND
// ============================================================================
$phpBin = '/usr/bin/php';
$logDestino = ($worker === 'ia')
    ? '/home/muuk9x7m9to5/public_html/cualify/worker_ia.log'
    : '/home/muuk9x7m9to5/public_html/cualify/worker.log';

// Ejecutar con nice y nohup para no bloquear
$cmd = sprintf(
    'nice -n 19 %s %s >> %s 2>&1 &',
    escapeshellcmd($phpBin),
    escapeshellcmd($comandos[$worker]),
    escapeshellcmd($logDestino)
);

exec($cmd);