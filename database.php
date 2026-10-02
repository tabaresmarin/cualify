<?php
require_once __DIR__ . '/config.php';

/**
 * Devuelve una conexión PDO a la base de datos.
 *
 * @param bool $forzarNueva Si es true, cierra la conexión cacheada y crea una nueva.
 *                          Útil tras operaciones largas (PageSpeed, Calendar) que
 *                          pueden exceder el wait_timeout de MySQL.
 * @return PDO
 */
function obtenerConexion(bool $forzarNueva = false) {
    static $pdo = null;

    // Forzar reconexión: descartar la conexión actual
    if ($forzarNueva && $pdo !== null) {
        $pdo = null;
    }

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Prepared statements nativos (más seguro)
            PDO::ATTR_PERSISTENT         => false, // No usar conexiones persistentes
        ]);
    }

    return $pdo;
}

function obtenerClienteActual() {
    $pdo = obtenerConexion();
    $stmt = $pdo->prepare('SELECT id FROM clients WHERE whatsapp_phone_number_id = ? LIMIT 1');
    $stmt->execute([WA_PHONE_NUMBER_ID]);
    $cliente = $stmt->fetch();
    if ($cliente) {
        return (int)$cliente['id'];
    }

    $stmt = $pdo->query('SELECT id FROM clients ORDER BY id ASC LIMIT 1');
    $cliente = $stmt->fetch();
    return $cliente ? (int)$cliente['id'] : null;
}

// Sin llamadas en el codigo actual (el flujo real usa crearLeadEnCalificacion
// en lead_qualifier.php y el ON DUPLICATE KEY de flow_data_endpoint.php). Se
// deja por compatibilidad, pero ya alineado con el schema: sin client_id ni
// meta_lead_id, y con status dentro del enum. El $metaLeadId se conserva en la
// firma aunque ya no se use.
function buscarOCrearLead($phone, $metaLeadId = null) {
    $pdo  = obtenerConexion();
    $stmt = $pdo->prepare('SELECT * FROM leads WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $lead = $stmt->fetch();
    if ($lead) {
        return $lead;
    }

    // El enum de leads.status es ('nuevo','en_calificacion','calificado',
    // 'descartado'): 'new' esta fuera del enum y el INSERT fallaria. Ademas
    // `leads` no tiene columnas client_id ni meta_lead_id.
    $stmt = $pdo->prepare('INSERT INTO leads (phone, status) VALUES (?, "nuevo")');
    $stmt->execute([$phone]);

    return [
        'id'     => (int)$pdo->lastInsertId(),
        'phone'  => $phone,
        'status' => 'nuevo',
    ];
}

function obtenerPasoActual($leadId) {
    $pdo  = obtenerConexion();
    $stmt = $pdo->prepare('SELECT current_step FROM conversation_states WHERE lead_id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $fila = $stmt->fetch();
    return $fila ? (int)$fila['current_step'] : null;
}

function guardarPaso($leadId, $paso) {
    $pdo  = obtenerConexion();
    $stmt = $pdo->prepare('SELECT id FROM conversation_states WHERE lead_id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $existe = $stmt->fetch();

    if ($existe) {
        $pdo->prepare('UPDATE conversation_states SET current_step = ? WHERE lead_id = ?')
            ->execute([$paso, $leadId]);
    } else {
        $pdo->prepare('INSERT INTO conversation_states (lead_id, current_step) VALUES (?, ?)')
            ->execute([$leadId, $paso]);
    }
}

function actualizarEstadoLead($leadId, $estado) {
    $pdo = obtenerConexion();
    $pdo->prepare('UPDATE leads SET status = ? WHERE id = ?')
        ->execute([$estado, $leadId]);
}

function guardarDatosLead($leadId, $respuesta) {
    $pdo  = obtenerConexion();
    $stmt = $pdo->prepare('SELECT data_collected FROM conversation_states WHERE lead_id = ? LIMIT 1');
    $stmt->execute([$leadId]);
    $fila = $stmt->fetch();

    $actuales = ($fila && $fila['data_collected']) ? json_decode($fila['data_collected'], true) : [];
    $actuales[] = ['respuesta' => $respuesta, 'fecha' => date('c')];

    $pdo->prepare('UPDATE conversation_states SET data_collected = ? WHERE lead_id = ?')
        ->execute([json_encode($actuales, JSON_UNESCAPED_UNICODE), $leadId]);
}