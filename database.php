<?php
require_once __DIR__ . '/config.php';

function obtenerConexion() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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

function buscarOCrearLead($phone, $metaLeadId = null) {
    $pdo  = obtenerConexion();
    $stmt = $pdo->prepare('SELECT * FROM leads WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    $lead = $stmt->fetch();
    if ($lead) {
        return $lead;
    }

    $clientId = obtenerClienteActual();
    $stmt = $pdo->prepare('INSERT INTO leads (client_id, meta_lead_id, phone, status) VALUES (?, ?, ?, "new")');
    $stmt->execute([$clientId, $metaLeadId, $phone]);

    return [
        'id'        => (int)$pdo->lastInsertId(),
        'client_id' => $clientId,
        'phone'     => $phone,
        'status'    => 'new',
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