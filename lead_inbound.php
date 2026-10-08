<?php
/**
 * lead_inbound.php — Endpoint Webhook "Speed-to-Lead" para ingesta de leads desde
 * Meta Lead Ads, formularios web, Elementor, Typeform, Zapier, Make o CRM.
 *
 * RECIBE:
 *   - POST (JSON o Form Data)
 *   - Autenticación mediante token (?token=XYZ o header X-Inbound-Token / Authorization: Bearer XYZ)
 *
 * ACCIÓN (< 30s):
 *   1. Normaliza teléfono y registra/actualiza el lead en la BD (status = 'nuevo').
 *   2. Si se proporciona plantilla HSM (o INBOUND_DEFAULT_TEMPLATE), envía WhatsApp proactivo.
 *   3. Encola un job 'ia_message' y dispara el worker de IA en segundo plano de inmediato.
 */

// Silenciar avisos en pantalla; responder siempre en JSON
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/lead_qualifier.php';
require_once __DIR__ . '/whatsapp_api.php';

// ============================================================================
// 1. AUTENTICACIÓN
// ============================================================================

$tokenEsperado = valorEntorno('INBOUND_LEAD_TOKEN') ?: valorEntorno('WORKER_TRIGGER_TOKEN');

// Extraer token de GET, headers o Authorization Bearer
$tokenRecibido = $_GET['token'] ?? $_POST['token'] ?? null;

if (!$tokenRecibido) {
    $headers = function_exists('getallheaders') ? getallheaders() : $_SERVER;
    foreach ($headers as $key => $val) {
        $keyLower = strtolower((string)$key);
        if ($keyLower === 'x-inbound-token') {
            $tokenRecibido = $val;
            break;
        }
        if ($keyLower === 'authorization' || $keyLower === 'http_authorization') {
            if (preg_match('/Bearer\s+(.*)$/i', (string)$val, $matches)) {
                $tokenRecibido = $matches[1];
                break;
            }
        }
    }
}

if (!empty($tokenEsperado) && $tokenRecibido !== $tokenEsperado) {
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'message' => 'No autorizado: Token de ingesta inválido o no proporcionado.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 2. LECTURA Y NORMALIZACIÓN DE DATOS ENTRANTE
// ============================================================================

$rawInput = file_get_contents('php://input');
$dataJson = json_decode($rawInput, true);

$data = is_array($dataJson) ? array_merge($_POST, $dataJson) : $_POST;

$phoneRaw  = $data['phone'] ?? $data['telefono'] ?? $data['celular'] ?? null;
$name      = trim((string)($data['name'] ?? $data['nombre'] ?? ''));
$email     = trim((string)($data['email'] ?? $data['correo'] ?? ''));
$urlSitio  = trim((string)($data['url_sitio'] ?? $data['website'] ?? $data['url'] ?? ''));
$fuente    = trim((string)($data['source'] ?? $data['fuente'] ?? 'Inbound Webhook'));
$template  = trim((string)($data['template_name'] ?? $data['plantilla'] ?? valorEntorno('INBOUND_DEFAULT_TEMPLATE') ?? ''));
$triggerAi = filter_var($data['trigger_ai'] ?? true, FILTER_VALIDATE_BOOLEAN);

if (!$phoneRaw) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Parámetro requerido faltante: "phone" (teléfono en formato E.164 o dígitos).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Limpiar teléfono: conservar solo dígitos
$phone = preg_replace('/\D/', '', (string)$phoneRaw);

if (strlen($phone) < 10) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'El teléfono proporcionado no tiene la longitud mínima requerida (10 dígitos).'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Normalizar URL de sitio si viene
$urlNormalizada = null;
if ($urlSitio !== '') {
    $urlNormalizada = normalizarUrlSitio($urlSitio);
}

// ============================================================================
// 3. PERSISTENCIA EN BASE DE DATOS (`leads`)
// ============================================================================

$pdo = obtenerConexion();
$leadId = null;

try {
    // Upsert lead
    $stmt = $pdo->prepare(
        "INSERT INTO leads (phone, name, email, url_sitio, status, created_at)
         VALUES (?, ?, ?, ?, 'nuevo', NOW())
         ON DUPLICATE KEY UPDATE
            name = IF(VALUES(name) != '', VALUES(name), name),
            email = IF(VALUES(email) != '', VALUES(email), email),
            url_sitio = IF(VALUES(url_sitio) IS NOT NULL, VALUES(url_sitio), url_sitio),
            status = IF(status = 'descartado', 'nuevo', status)"
    );
    $stmt->execute([
        $phone,
        $name !== '' ? $name : null,
        $email !== '' ? $email : null,
        $urlNormalizada,
    ]);

    // Obtener ID del lead
    $stmtId = $pdo->prepare("SELECT id FROM leads WHERE phone = ? LIMIT 1");
    $stmtId->execute([$phone]);
    $filaId = $stmtId->fetch(PDO::FETCH_ASSOC);
    $leadId = $filaId ? (int)$filaId['id'] : null;

} catch (PDOException $e) {
    error_log('[lead_inbound] Error guardando lead: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Error interno al guardar los datos del lead.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================================
// 4. SPEED-TO-LEAD: DISPARO ACTIVO DE ATENCIÓN
// ============================================================================

$plantillaEnviada = false;
$workerDisparado  = false;

// 4a. Si se definió una plantilla HSM aprobada, enviarla directamente
if ($template !== '') {
    try {
        $parametrosPlantilla = $name !== '' ? [$name] : ['Hola'];
        $res = enviarPlantillaWhatsApp($phone, $template, 'es', $parametrosPlantilla);
        $plantillaEnviada = true;
        error_log("[lead_inbound] Plantilla HSM '$template' enviada a $phone");
    } catch (Throwable $e) {
        error_log("[lead_inbound] Error enviando plantilla HSM: " . $e->getMessage());
    }
}

// 4b. Disparar atención inmediata con IA si está habilitado
if ($triggerAi) {
    $saludoPrompt = "Hola, solicitaste información desde " . ($fuente !== '' ? $fuente : "nuestro formulario") . ". ";
    if ($name !== '') {
        $saludoPrompt .= "Mi nombre es {$name}. ";
    }
    if ($urlNormalizada) {
        $saludoPrompt .= "Mi sitio web es {$urlNormalizada}. ";
    }
    $saludoPrompt .= "¿Podrían brindarme información sobre sus servicios?";

    $jobId = encolarMensajeIA($pdo, $phone, [
        'tipo'   => 'text',
        'texto'  => $saludoPrompt,
        'fuente' => $fuente,
    ]);

    if ($jobId) {
        dispararWorker('ia');
        $workerDisparado = true;
        error_log("[lead_inbound] Encolado job ia_message #$jobId y worker disparado para $phone");
    }
}

// ============================================================================
// 5. RESPUESTA DE ÉXITO
// ============================================================================

http_response_code(200);
echo json_encode([
    'status'           => 'success',
    'message'          => 'Lead registrado y speed-to-lead activado en < 30s.',
    'phone'            => $phone,
    'lead_id'          => $leadId,
    'template_sent'    => $plantillaEnviada,
    'worker_triggered' => $workerDisparado,
], JSON_UNESCAPED_UNICODE);
