<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/whatsapp_api.php';

// Obtener el número de teléfono
$to = $argv[1] ?? ($_GET['to'] ?? null);

if (!$to) {
    echo "Falta el parámetro 'to' (número de teléfono). Ej: test_send.php?to=573045235358";
    exit(1);
}

echo "<h3>Prueba de Envío de Plantilla</h3>";
echo "<strong>Destino:</strong> " . htmlspecialchars($to) . "<br>";

// Probar la plantilla aprobada en idioma "en"
// La plantilla "consultar_tarifas" tiene un botón de tipo Flow ("Consultar Tarifas"),
// por lo que SIEMPRE debe incluirse este componente, aunque el Flow no reciba datos iniciales.
// flow_token = número de teléfono: es el único dato que flow_data_endpoint.php recibe
// para saber a qué lead/conversación pertenece cada intercambio de datos del Flow.
$respuestaRaw = enviarPlantillaWhatsApp($to, "consultar_servicios", "en", [], [
    [
        'sub_type'   => 'flow',
        'index'      => 0,
        'flow_token' => $to,
    ]
]);
$respuestaDecodificada = json_decode($respuestaRaw, true);

echo "<pre>";
print_r($respuestaDecodificada);
echo "</pre>";

if (isset($respuestaDecodificada['messages'][0]['id'])) {
    echo "<p style='color:green;'><strong>¡Éxito!</strong> El mensaje de plantilla fue aceptado por Meta (ID: " . $respuestaDecodificada['messages'][0]['id'] . ")</p>";
} else {
    echo "<p style='color:red;'><strong>Ocurrió un error al enviar la plantilla.</strong></p>";
}