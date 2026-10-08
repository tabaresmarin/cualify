<?php
/**
 * worker_followup.php — Worker de re-engagement y seguimiento automático a leads fríos.
 *
 * Se ejecuta por cron cada 30-60 minutos.
 * Identifica leads en proceso de calificación que dejaron de responder y les envía
 * pings suaves de seguimiento a las 2 horas y a las 24 horas de inactividad.
 *
 * Configuración del cron en cPanel:
 *   0 * * * * /usr/bin/php /home/muuk9x7m9to5/public_html/cualify/worker_followup.php >> /home/muuk9x7m9to5/public_html/cualify/worker.log 2>&1
 */

// ============================================================================
// SEGURIDAD: CLI, cron_runner o token válido
// ============================================================================
require_once __DIR__ . '/config.php';

$esCli = (
    php_sapi_name() === 'cli'
    || php_sapi_name() === 'cli-server'
    || !isset($_SERVER['REMOTE_ADDR'])
    || !isset($_SERVER['REQUEST_METHOD'])
);

$tokenRecibido = $_GET['token'] ?? '';
$tokenValido = !empty($tokenRecibido) && hash_equals((string)valorEntorno('WORKER_TRIGGER_TOKEN'), $tokenRecibido);
$esRunnerValido = defined('CRON_RUNNER_ACTIVE') || $tokenValido;

if (!$esCli && !$esRunnerValido) {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde CLI o con un token válido.');
}

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/whatsapp_api.php';
require_once __DIR__ . '/lead_qualifier.php';

$pdo = obtenerConexion();

error_log("[worker_followup] Inicio de verificación de seguimiento a leads fríos...");

// ============================================================================
// CONSULTAR LEADS EN CALIFICACIÓN INACTIVOS (> 2 Horas)
// ============================================================================

try {
    $stmt = $pdo->prepare(
        "SELECT l.id, l.phone, l.name, l.status,
                TIMESTAMPDIFF(
                    MINUTE,
                    COALESCE(
                        (SELECT MAX(created_at) FROM conversation_history WHERE phone = l.phone AND role = 'user'),
                        l.created_at
                    ),
                    NOW()
                ) AS minutos_inactivo
         FROM leads l
         WHERE l.status IN ('nuevo', 'en_calificacion')
           AND TIMESTAMPDIFF(
                 MINUTE,
                 COALESCE(
                     (SELECT MAX(created_at) FROM conversation_history WHERE phone = l.phone AND role = 'user'),
                     l.created_at
                 ),
                 NOW()
               ) >= 120
         ORDER BY l.id DESC
         LIMIT 50"
    );
    $stmt->execute();
    $leadsFrios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[worker_followup] Error consultando leads fríos: " . $e->getMessage());
    exit(1);
}

if (empty($leadsFrios)) {
    error_log("[worker_followup] No se encontraron leads fríos requiriendo seguimiento.");
    exit(0);
}

$seguimientos2h  = 0;
$seguimientos24h = 0;

foreach ($leadsFrios as $lead) {
    $phone           = $lead['phone'];
    $nombre          = trim((string)($lead['name'] ?? ''));
    $nombreFormateado= $nombre !== '' ? " " . $nombre : "";
    $minutosInactivo = (int)$lead['minutos_inactivo'];

    // ------------------------------------------------------------------------
    // LEVEL 2: Seguimiento 24 horas después (>= 1440 minutos)
    // ------------------------------------------------------------------------
    if ($minutosInactivo >= 1440) {
        if (!yaSeEnvioSeguimiento($pdo, $phone, 'followup_24h')) {
            $msg24h = "Hola{$nombreFormateado} 👋, sabemos que puedes estar ocupado/a.\n\n"
                    . "Si aún estás interesado/a en impulsar las ventas digitales o renovar el sitio web de tu empresa, "
                    . "puedes agendar tu llamada de asesoría gratuita escribiendo *AGENDAR*.\n\n"
                    . "¡Quedamos a tu disposición en EISO!";

            if (enviarMensajeTexto($phone, $msg24h)) {
                registrarSeguimientoEnviado($pdo, $phone, 'followup_24h');
                $seguimientos24h++;
                error_log("[worker_followup] Seguimiento 24h enviado a $phone ($minutosInactivo mins inactivo)");
            }
        }
        continue;
    }

    // ------------------------------------------------------------------------
    // LEVEL 1: Seguimiento 2 horas después (>= 120 minutos y < 1440 minutos)
    // ------------------------------------------------------------------------
    if ($minutosInactivo >= 120) {
        if (!yaSeEnvioSeguimiento($pdo, $phone, 'followup_2h')) {
            $msg2h = "Hola{$nombreFormateado} 👋, ¿pudiste revisar nuestro mensaje anterior?\n\n"
                   . "Sigo por aquí si tienes alguna inquietud o deseas agendar tu asesoría con EISO. 😊";

            if (enviarMensajeTexto($phone, $msg2h)) {
                registrarSeguimientoEnviado($pdo, $phone, 'followup_2h');
                $seguimientos2h++;
                error_log("[worker_followup] Seguimiento 2h enviado a $phone ($minutosInactivo mins inactivo)");
            }
        }
    }
}

error_log("[worker_followup] Fin del worker. Seguimientos 2h: $seguimientos2h, 24h: $seguimientos24h");

// ============================================================================
// FUNCIONES AUXILIARES
// ============================================================================

function yaSeEnvioSeguimiento(PDO $pdo, string $phone, string $jobType): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM jobs_queue
             WHERE phone = ? AND job_type = ?
             LIMIT 1"
        );
        $stmt->execute([$phone, $jobType]);
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("[worker_followup] Error verificando idempotencia de seguimiento: " . $e->getMessage());
        return false;
    }
}

function registrarSeguimientoEnviado(PDO $pdo, string $phone, string $jobType): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO jobs_queue (phone, job_type, payload, status, created_at, processed_at)
             VALUES (?, ?, '{}', 'done', NOW(), NOW())"
        );
        $stmt->execute([$phone, $jobType]);
    } catch (PDOException $e) {
        error_log("[worker_followup] Error registrando seguimiento enviado: " . $e->getMessage());
    }
}
