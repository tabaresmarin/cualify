<?php
/**
 * worker_pagespeed.php — Procesa la cola de trabajos de PageSpeed (PDO).
 *
 * Se ejecuta por cron cada minuto. Toma 1 job pendiente, lo procesa y
 * actualiza su estado. Si falla, incrementa el contador de intentos.
 *
 * Configura el cron en cPanel así:
 *   * * * * * /usr/bin/php /home/muuk9x7m9to5/public_html/cualify/worker_pagespeed.php >> /home/muuk9x7m9to5/public_html/cualify/worker.log 2>&1
 *
 * Ajusta la ruta del binario PHP según tu hosting (puede ser /usr/local/bin/php
 * o /usr/local/bin/lsphp en LiteSpeed).
 */

// ============================================================================
// SEGURIDAD: Detectar si es CLI o HTTP (compatible con LiteSpeed)
// ============================================================================

$esCli = (
    php_sapi_name() === 'cli'
    || php_sapi_name() === 'cli-server'
    || !isset($_SERVER['REMOTE_ADDR'])
    || !isset($_SERVER['REQUEST_METHOD'])
);

$tokenValido = isset($_GET['token'])
    && function_exists('valorEntorno')
    && $_GET['token'] === valorEntorno('WORKER_SECRET_TOKEN');

if (!$esCli && !$tokenValido) {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde CLI o con token válido.');
}

// Límites generosos
ini_set('memory_limit', '256M');
set_time_limit(300); // 5 minutos máximo por job

// Log rotativo básico: si supera 5MB, se trunca
$logPath = __DIR__ . '/worker.log';
if (file_exists($logPath) && filesize($logPath) > 5 * 1024 * 1024) {
    file_put_contents($logPath, '');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead_qualifier.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/pagespeed_api.php';
require_once __DIR__ . '/google_calendar_api.php';
require_once __DIR__ . '/appointment_slots.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// DIAGNÓSTICO: confirmar arranque del worker
// ============================================================================
error_log('[worker_pagespeed] ========== INICIO DE EJECUCIÓN ==========');

$maxJobs = 1; // Procesar como máximo N jobs por ejecución

error_log('[worker_pagespeed] Intentando conectar a la BD...');
$pdo = obtenerConexion();
if (!$pdo) {
    error_log('[worker_pagespeed] ERROR: No se pudo conectar a la BD');
    exit(1);
}
error_log('[worker_pagespeed] Conexión a BD OK. Buscando jobs pendientes...');

// Contar jobs pendientes para diagnóstico
try {
    $stmtCount = $pdo->query("SELECT COUNT(*) AS total FROM jobs_queue WHERE status = 'pending'");
    $totalPendientes = (int) $stmtCount->fetch(PDO::FETCH_ASSOC)['total'];
    error_log("[worker_pagespeed] Jobs pendientes encontrados: $totalPendientes");
} catch (PDOException $e) {
    error_log('[worker_pagespeed] Error contando jobs: ' . $e->getMessage());
    $totalPendientes = 0;
}

for ($i = 0; $i < $maxJobs; $i++) {

    // ========================================================================
    // 1. Tomar un job pendiente con bloqueo
    // ========================================================================
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->query(
            "SELECT id, phone, payload, attempts, max_attempts
             FROM jobs_queue
             WHERE status = 'pending'
             ORDER BY created_at ASC
             LIMIT 1
             FOR UPDATE"
        );
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $pdo->commit();
            error_log('[worker_pagespeed] No hay jobs pendientes en este ciclo.');
            break;
        }

        error_log("[worker_pagespeed] Tomando job {$job['id']} (phone: {$job['phone']}, attempts: {$job['attempts']})");

        $pdo->prepare(
            "UPDATE jobs_queue
             SET status = 'processing', started_at = NOW(), attempts = attempts + 1
             WHERE id = ?"
        )->execute([$job['id']]);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[worker_pagespeed] Error tomando job: ' . $e->getMessage());
        break;
    }

    // ========================================================================
    // 2. Procesar el job fuera de la transacción
    // ========================================================================
    $payload = json_decode($job['payload'] ?? '{}', true) ?: [];
    $phone   = $job['phone'];

    $exito        = false;
    $errorMensaje = null;

    try {
        $exito = procesarLeadFlowAsincrono($phone, $pdo);
        if (!$exito) {
            $errorMensaje = 'procesarLeadFlowAsincrono devolvió false';
        }
    } catch (Throwable $e) {
        $errorMensaje = $e->getMessage();

        error_log("[worker_pagespeed] Excepción procesando job {$job['id']}: " . $e->getMessage());
        error_log("[worker_pagespeed] Archivo: " . $e->getFile() . ":" . $e->getLine());
        error_log("[worker_pagespeed] Stack trace:\n" . $e->getTraceAsString());
        error_log("[worker_pagespeed] Job phone: $phone");
        error_log("[worker_pagespeed] Job payload: " . json_encode($payload));
    }

    // ========================================================================
    // 3. Actualizar estado final del job
    // ========================================================================

    // Reconectar por si PageSpeed tardó lo suficiente como para que MySQL
    // cerrara la conexión por inactividad (wait_timeout).
    $pdo = reconectarSiEsNecesario($pdo);

    if ($exito) {
        try {
            $pdo->prepare(
                "UPDATE jobs_queue
                 SET status = 'done', processed_at = NOW(), last_error = NULL
                 WHERE id = ?"
            )->execute([$job['id']]);
            error_log("[worker_pagespeed] Job {$job['id']} completado para $phone");
        } catch (PDOException $e) {
            error_log("[worker_pagespeed] Error marcando job {$job['id']} como done: " . $e->getMessage());
        }
    } else {
        $nuevoStatus = ($job['attempts'] + 1 >= $job['max_attempts']) ? 'failed' : 'pending';
        try {
            $pdo->prepare(
                "UPDATE jobs_queue
                 SET status = ?, last_error = ?, processed_at = IF(? = 'failed', NOW(), NULL)
                 WHERE id = ?"
            )->execute([$nuevoStatus, $errorMensaje, $nuevoStatus, $job['id']]);

            error_log("[worker_pagespeed] Job {$job['id']} falló (intento {$job['attempts']}/{$job['max_attempts']}): $errorMensaje");
        } catch (PDOException $e) {
            error_log("[worker_pagespeed] Error actualizando job {$job['id']} tras fallo: " . $e->getMessage());
        }
    }
}

error_log('[worker_pagespeed] ========== FIN DE EJECUCIÓN ==========');
exit(0);