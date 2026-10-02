<?php
/**
 * worker_ia.php — Procesa los mensajes entrantes con IA en background.
 *
 * Se ejecuta por cron cada minuto. Toma UN job de tipo 'ia_message' de la cola,
 * lo procesa (llamando a Gemini o al flujo correspondiente) y envía la respuesta
 * por WhatsApp. Luego marca el job como 'done' (o 'pending' si falla para reintentar).
 *
 * Configura el cron en cPanel así:
 *   * * * * * /usr/bin/php /home/muuk9x7m9to5/public_html/cualify/worker_ia.php >> /home/muuk9x7m9to5/public_html/cualify/worker_ia.log 2>&1
 */

// ============================================================================
// SEGURIDAD: solo CLI
// ============================================================================
$esCli = (
    php_sapi_name() === 'cli'
    || php_sapi_name() === 'cli-server'
    || !isset($_SERVER['REMOTE_ADDR'])
    || !isset($_SERVER['REQUEST_METHOD'])
);

if (!$esCli) {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde CLI.');
}

// ============================================================================
// CONFIGURACIÓN
// ============================================================================
ini_set('memory_limit', '256M');
set_time_limit(300); // 5 minutos máx por job (Gemini puede tardar con reintentos)

// Log rotativo
$logPath = __DIR__ . '/worker_ia.log';
if (file_exists($logPath) && filesize($logPath) > 5 * 1024 * 1024) {
    file_put_contents($logPath, '');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lead_qualifier.php';
require_once __DIR__ . '/ia_engine.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/database.php';

error_log('[worker_ia] ========== INICIO DE EJECUCIÓN ==========');

// ============================================================================
// CONECTAR A BD
// ============================================================================
$pdo = obtenerConexion();
if (!$pdo) {
    error_log('[worker_ia] ERROR: No se pudo conectar a la BD');
    exit(1);
}

// ============================================================================
// TOMAR UN JOB PENDIENTE
// ============================================================================
try {
    $pdo->beginTransaction();

    $stmt = $pdo->query(
        "SELECT id, phone, payload, attempts, max_attempts
         FROM jobs_queue
         WHERE status = 'pending' AND job_type = 'ia_message'
         ORDER BY created_at ASC
         LIMIT 1
         FOR UPDATE"
    );
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $pdo->commit();
        error_log('[worker_ia] No hay jobs ia_message pendientes.');
        error_log('[worker_ia] ========== FIN DE EJECUCIÓN ==========');
        exit(0);
    }

    error_log("[worker_ia] Tomando job {$job['id']} (phone: {$job['phone']}, attempts: {$job['attempts']})");

    $pdo->prepare(
        "UPDATE jobs_queue
         SET status = 'processing', started_at = NOW(), attempts = attempts + 1
         WHERE id = ?"
    )->execute([$job['id']]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[worker_ia] Error tomando job: ' . $e->getMessage());
    exit(1);
}

// ============================================================================
// PROCESAR EL JOB
// ============================================================================
$payload   = json_decode($job['payload'] ?? '{}', true) ?: [];
$phone     = $job['phone'];
$tipo      = $payload['tipo'] ?? 'text';
$messageId = $payload['message_id'] ?? '';

$exito        = false;
$errorMensaje = null;
$respuesta    = null;

// Mostrar typing indicator si tenemos message_id
if (!empty($messageId)) {
    try {
        mostrarTypingIndicator($messageId);
        error_log("[worker_ia] Typing indicator enviado para message_id=$messageId");
    } catch (Throwable $e) {
        error_log("[worker_ia] Error typing indicator: " . $e->getMessage());
    }
}

try {
    switch ($tipo) {
        case 'text':
            $texto = $payload['texto'] ?? '';
            if ($texto === '') {
                throw new RuntimeException('Payload tipo=text sin campo "texto"');
            }
            error_log("[worker_ia] Procesando texto: '$texto'");
            $respuesta = procesarMensajeEntranteConIA($phone, $texto, $pdo);
            break;

        case 'slot_selection':
            $slotId = $payload['slot_id'] ?? '';
            if ($slotId === '') {
                throw new RuntimeException('Payload tipo=slot_selection sin campo "slot_id"');
            }
            error_log("[worker_ia] Procesando selección de slot: $slotId");
            $respuesta = procesarSeleccionSlotYDevolverTexto($phone, $slotId, $pdo);
            break;

        default:
            throw new RuntimeException("Tipo de payload desconocido: $tipo");
    }

    if ($respuesta !== null && $respuesta !== '') {
        error_log("[worker_ia] Enviando respuesta de " . strlen($respuesta) . " chars a $phone...");

        $resultadoEnvio = enviarTextoWhatsApp($phone, $respuesta);

        // Log completo del resultado de la API
        error_log("[worker_ia] Resultado de la API: " . json_encode($resultadoEnvio));

        // Verificar el código HTTP real
        $httpCode = $resultadoEnvio['http_code'] ?? 0;

        if ($httpCode === 200 || $httpCode === 201) {
            error_log("[worker_ia] ✅ Respuesta enviada correctamente a $phone");
            $exito = true;
        } else {
            $errorMensaje = 'API WhatsApp devolvió HTTP ' . $httpCode . ': '
                        . substr($resultadoEnvio['respuesta'] ?? '', 0, 300);
            error_log("[worker_ia] ❌ Error enviando a $phone: $errorMensaje");
            $exito = false;
        }
    } else {
        $errorMensaje = 'La función no devolvió texto';
        error_log("[worker_ia] $errorMensaje para phone=$phone");
    }
} catch (Throwable $e) {
    $errorMensaje = $e->getMessage();
    error_log("[worker_ia] Excepción procesando job {$job['id']}: " . $e->getMessage());
    error_log("[worker_ia] Archivo: " . $e->getFile() . ":" . $e->getLine());
    error_log("[worker_ia] Stack trace:\n" . $e->getTraceAsString());
}

// ============================================================================
// ACTUALIZAR ESTADO DEL JOB
// ============================================================================
$pdo = reconectarSiEsNecesario($pdo);

if ($exito) {
    try {
        $pdo->prepare(
            "UPDATE jobs_queue
             SET status = 'done', processed_at = NOW(), last_error = NULL
             WHERE id = ?"
        )->execute([$job['id']]);
        error_log("[worker_ia] Job {$job['id']} completado para $phone");
    } catch (PDOException $e) {
        error_log("[worker_ia] Error marcando job {$job['id']} como done: " . $e->getMessage());
    }
} else {
    $nuevoStatus = ($job['attempts'] + 1 >= $job['max_attempts']) ? 'failed' : 'pending';
    try {
        $pdo->prepare(
            "UPDATE jobs_queue
             SET status = ?, last_error = ?, processed_at = IF(? = 'failed', NOW(), NULL)
             WHERE id = ?"
        )->execute([$nuevoStatus, $errorMensaje, $nuevoStatus, $job['id']]);
        error_log("[worker_ia] Job {$job['id']} falló (intento {$job['attempts']}/{$job['max_attempts']}): $errorMensaje");
    } catch (PDOException $e) {
        error_log("[worker_ia] Error actualizando job {$job['id']} tras fallo: " . $e->getMessage());
    }
}

error_log('[worker_ia] ========== FIN DE EJECUCIÓN ==========');
exit(0);