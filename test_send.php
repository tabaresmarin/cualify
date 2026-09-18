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
$resultado = enviarPlantillaWhatsApp($to, "consultar_tarifas", "en", [], [
    [
        'sub_type'   => 'flow',
        'index'      => 0,
        'flow_token' => 'unused', // reemplaza por un token único si necesitas correlacionar la sesión del Flow
    ]
]);
$respuestaDecodificada = json_decode($resultado['respuesta'], true);

echo "<pre>";
print_r($resultado);
echo "</pre>";

echo "<p><strong>HTTP:</strong> " . $resultado['http_code'] . " — <strong>cURL:</strong> " . ($resultado['error'] ?: 'sin error') . "</p>";

if ($resultado['http_code'] !== 200) {
    echo "<p style='color:red;'><strong>Meta rechazó el envío (HTTP " . $resultado['http_code'] . ").</strong> Revisa el error:</p>";
} elseif (isset($respuestaDecodificada['messages'][0]['id'])) {
    echo "<p style='color:green;'><strong>¡Éxito!</strong> El mensaje de plantilla fue aceptado por Meta (ID: " . $respuestaDecodificada['messages'][0]['id'] . ")</p>";
} else {
    echo "<p style='color:red;'><strong>Ocurrió un error al enviar la plantilla.</strong></p>";
}