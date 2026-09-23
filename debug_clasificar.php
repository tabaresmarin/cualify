<?php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/lead_qualifier.php';

// Reflejar la función para ver dónde está definida
$ref = new ReflectionFunction('clasificarPorRendimiento');
echo "Definida en: " . $ref->getFileName() . " línea " . $ref->getStartLine() . "\n\n";

// Probar con score 30 (debería devolver array)
$resultado = clasificarPorRendimiento(30);
echo "Tipo devuelto: " . gettype($resultado) . "\n";
echo "Contenido:\n";
var_dump($resultado);