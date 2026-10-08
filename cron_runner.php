<?php
/**
 * cron_runner.php — Runner de tareas cron vía HTTP.
 *
 * Diseñado específicamente para hosting cPanel donde Jailshell / SSH / terminal
 * están deshabilitados. Permite ejecutar workers de forma segura vía HTTP
 * o mediante cPanel Cron con /usr/bin/curl o servicios externos como cron-job.org.
 *
 * Uso:
 *   /cron_runner.php?job=ia&token=TU_WORKER_TRIGGER_TOKEN
 *   /cron_runner.php?job=pagespeed&token=TU_WORKER_TRIGGER_TOKEN
 *   /cron_runner.php?job=reminders&token=TU_WORKER_TRIGGER_TOKEN
 *   /cron_runner.php?job=followup&token=TU_WORKER_TRIGGER_TOKEN
 */

define('CRON_RUNNER_ACTIVE', true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';

$tokenEsperado = valorEntorno('WORKER_TRIGGER_TOKEN');
$tokenRecibido = $_GET['token'] ?? $_GET['key'] ?? '';

if (empty($tokenEsperado) || !hash_equals($tokenEsperado, (string)$tokenRecibido)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Acceso denegado: Token de disparo inválido o no proporcionado.');
}

$job = $_GET['job'] ?? $_GET['worker'] ?? 'ia';

// Aumentar tiempo límite para llamadas HTTP de workers
set_time_limit(300);

header('Content-Type: text/plain; charset=utf-8');

switch ($job) {
    case 'ia':
        require_once __DIR__ . '/worker_ia.php';
        echo "Worker IA ejecutado correctamente vía HTTP.";
        break;

    case 'pagespeed':
        require_once __DIR__ . '/worker_pagespeed.php';
        echo "Worker PageSpeed ejecutado correctamente vía HTTP.";
        break;

    case 'reminders':
        require_once __DIR__ . '/worker_reminders.php';
        echo "Worker Reminders ejecutado correctamente vía HTTP.";
        break;

    case 'followup':
        require_once __DIR__ . '/worker_followup.php';
        echo "Worker Followup ejecutado correctamente vía HTTP.";
        break;

    default:
        http_response_code(400);
        echo "Job desconocido: {$job}";
        break;
}
