<?php
header('Content-Type: application/json');

// Leer la solicitud de Meta Flow
$input = file_get_contents('php://input');
$data = json_decode($input, true);

$action = $data['action'] ?? '';
$payload = $data['data'] ?? [];

// Estructura de respuesta predeterminada para Meta Flows
$response = [
    "version" => "3.0",
    "screen" => "",
    "data" => []
];

if ($action === 'data_exchange') {
    $currentAction = $payload['action'] ?? '';

    // 1. Intercambio cuando el usuario ingresa la URL del sitio web
    if (isset($payload['website_url']) && empty($currentAction)) {
        $url = $payload['website_url'];

        // Consulta simulada o real a Google PageSpeed API
        $score = rand(35, 85); // Puedes conectar la API real aquí
        $message = ($score < 50) 
            ? "Tu sitio web presenta lentitud crítica en dispositivos móviles. Es urgente actualizarlo." 
            : "Tu sitio tiene un rendimiento aceptable, pero se puede optimizar para convertir más leads.";

        $response['screen'] = 'PAGESPEED_RESULT';
        $response['data'] = array_merge($payload, [
            'performance_score' => $score,
            'score_bucket' => ($score < 50) ? 'muy_necesitado' : 'regular',
            'score_message' => $message
        ]);
    } 
    // 2. Intercambio para consultar los horarios disponibles (fetch_slots)
    elseif ($currentAction === 'fetch_slots') {
        $response['screen'] = 'APPOINTMENT_SLOTS';
        $response['data'] = array_merge($payload, [
            'slots' => [
                ['id' => '2026-09-22T10:00:00-05:00', 'title' => 'Lunes 22 sep, 10:00 a.m.'],
                ['id' => '2026-09-22T15:00:00-05:00', 'title' => 'Lunes 22 sep, 3:00 p.m.'],
                ['id' => '2026-09-23T11:00:00-05:00', 'title' => 'Martes 23 sep, 11:00 a.m.']
            ]
        ]);
    }
    // 3. Intercambio para confirmar la cita en MySQL / Google Calendar
    elseif ($currentAction === 'create_event') {
        $response['screen'] = 'SUCCESS';
        $response['data'] = array_merge($payload, [
            'appointment_label' => 'Lunes 22 sep, 10:00 a.m.',
            'advisor_email' => 'andres.tabares@eiso.com.co'
        ]);
    }
}

echo json_encode($response);