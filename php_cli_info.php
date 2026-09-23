<?php
header('Content-Type: text/plain');
echo "PHP_BINARY: " . PHP_BINARY . "\n";
echo "PHP_SAPI: " . PHP_SAPI . "\n";
echo "PHP_VERSION: " . PHP_VERSION . "\n";

// Buscar binarios de PHP en ubicaciones típicas
$rutas = [
    '/usr/bin/php',
    '/usr/local/bin/php',
    '/opt/cpanel/ea-php83/root/usr/bin/php',
    '/opt/cpanel/ea-php82/root/usr/bin/php',
    '/opt/cpanel/ea-php81/root/usr/bin/php',
];

foreach ($rutas as $ruta) {
    echo $ruta . ": " . (file_exists($ruta) ? "✅ existe" : "❌ no existe") . "\n";
}