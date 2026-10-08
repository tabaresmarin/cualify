<?php
/**
 * worker_reminders.php — Worker de recordatorios de citas automatizados por WhatsApp.
 *
 * Se ejecuta por cron cada 10-15 minutos.
 * Busca citas agendadas próximas (24 horas antes y 1 hora antes) y envía un
 * recordatorio por WhatsApp para prevenir "no-shows" (citas fantasma).
 *
 * Configuración del cron en cPanel:
 *   0,15,30,45 * * * * /usr/bin/php /home/muuk9x7m9to5/public_html/cualify/worker_reminders.php >> /home/muuk9x7m9to5/public_html/cualify/worker.log 2>&1
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

error_log("[worker_reminders] Inicio de verificación de recordatorios de citas...");

// ============================================================================
// CONSULTAR CITAS PRÓXIMAS (en las próximas 26 horas)
// ============================================================================

try {
    $stmt = $pdo->prepare(
        "SELECT id, phone, name, cita_fecha, cita_hora,
                TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(cita_fecha, ' ', cita_hora)) AS minutos_restantes
         FROM leads
         WHERE cita_fecha IS NOT NULL
           AND cita_hora IS NOT NULL
           AND status = 'calificado'
           AND CONCAT(cita_fecha, ' ', cita_hora) >= NOW()
           AND CONCAT(cita_fecha, ' ', cita_hora) <= DATE_ADD(NOW(), INTERVAL 26 HOUR)
         ORDER BY cita_fecha ASC, cita_hora ASC"
    );
    $stmt->execute();
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("[worker_reminders] Error consultando citas: " . $e->getMessage());
    exit(1);
}

if (empty($citas)) {
    error_log("[worker_reminders] No hay citas próximas que requieran recordatorio.");
    exit(0);
}

$enviados24h = 0;
$enviados1h  = 0;

foreach ($citas as $cita) {
    $phone           = $cita['phone'];
    $nombre          = trim((string)($cita['name'] ?? ''));
    $nombreFormateado= $nombre !== '' ? " " . $nombre : "";
    $citaFecha       = $cita['cita_fecha'];
    $citaHora        = substr((string)$cita['cita_hora'], 0, 5); // HH:MM
    $minutos         = (int)$cita['minutos_restantes'];

    $payloadClave = json_encode(['cita_fecha' => $citaFecha, 'cita_hora' => $citaHora]);

    // ------------------------------------------------------------------------
    // CASE 1: Recordatorio 24 horas antes (entre 22h y 26h = 1320 a 1560 mins)
    // ------------------------------------------------------------------------
    if ($minutos >= 1320 && $minutos <= 1560) {
        if (!yaSeEnvioRecordatorio($pdo, $phone, 'reminder_24h', $citaFecha)) {
            $msg24h = "⏰ *Recordatorio de tu cita con EISO*\n\n"
                    . "Hola{$nombreFormateado}, te recordamos que tienes una asesoría agendada para mañana *{$citaFecha} a las {$citaHora}*.\n\n"
                    . "¿Nos confirmas tu asistencia? Responde *SÍ* para confirmar o *AGENDAR* si deseas cambiar de horario. 🙌";

            if (enviarMensajeTexto($phone, $msg24h)) {
                registrarRecordatorioEnviado($pdo, $phone, 'reminder_24h', $payloadClave);
                $enviados24h++;
                error_log("[worker_reminders] Recordatorio 24h enviado a $phone para $citaFecha $citaHora");
            }
        }
    }

    // ------------------------------------------------------------------------
    // CASE 2: Recordatorio 1 hora antes (entre 30 y 90 mins)
    // ------------------------------------------------------------------------
    if ($minutos >= 30 && $minutos <= 90) {
        if (!yaSeEnvioRecordatorio($pdo, $phone, 'reminder_1h', $citaFecha)) {
            $msg1h = "🔔 *¡Tu cita con EISO es en 1 hora!*\n\n"
                   . "Hola{$nombreFormateado}, en aproximadamente 60 minutos nos conectaremos para tu llamada de asesoría ({$citaHora}).\n\n"
                   . "Si necesitas reagendar, responde *AGENDAR*. ¡Nos vemos pronto!";

            if (enviarMensajeTexto($phone, $msg1h)) {
                registrarRecordatorioEnviado($pdo, $phone, 'reminder_1h', $payloadClave);
                $enviados1h++;
                error_log("[worker_reminders] Recordatorio 1h enviado a $phone para $citaFecha $citaHora");
            }
        }
    }
}

error_log("[worker_reminders] Fin del worker. Recordatorios 24h enviados: $enviados24h, 1h enviados: $enviados1h");

// ============================================================================
// FUNCIONES AUXILIARES
// ============================================================================

function yaSeEnvioRecordatorio(PDO $pdo, string $phone, string $jobType, string $citaFecha): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT id FROM jobs_queue
             WHERE phone = ? AND job_type = ?
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.cita_fecha')) = ?
             LIMIT 1"
        );
        $stmt->execute([$phone, $jobType, $citaFecha]);
        return (bool)$stmt->fetch();
    } catch (PDOException $e) {
        error_log("[worker_reminders] Error verificando idempotencia: " . $e->getMessage());
        return false;
    }
}

function registrarRecordatorioEnviado(PDO $pdo, string $phone, string $jobType, string $payloadJson): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO jobs_queue (phone, job_type, payload, status, created_at, processed_at)
             VALUES (?, ?, ?, 'done', NOW(), NOW())"
        );
        $stmt->execute([$phone, $jobType, $payloadJson]);
    } catch (PDOException $e) {
        error_log("[worker_reminders] Error registrando recordatorio enviado: " . $e->getMessage());
    }
}
