<?php
/**
 * Script temporal de diagnóstico.
 * Escanea todos los archivos .php del directorio y detecta funciones duplicadas.
 *
 * ¡BORRA ESTE ARCHIVO DESPUÉS DE USARLO!
 */

header('Content-Type: text/plain; charset=utf-8');

$directorio = __DIR__;
$archivos = glob($directorio . '/*.php');
$funciones = []; // nombre => [archivos donde aparece]

foreach ($archivos as $archivo) {
    $nombreArchivo = basename($archivo);

    // Saltar este mismo script
    if ($nombreArchivo === 'diagnostico_funciones.php') continue;

    $contenido = file_get_contents($archivo);

    // Regex para detectar definiciones de funciones: "function nombre("
    // Ignora funciones anónimas y métodos de clase
    if (preg_match_all('/^\s*function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $contenido, $matches)) {
        foreach ($matches[1] as $nombreFuncion) {
            if (!isset($funciones[$nombreFuncion])) {
                $funciones[$nombreFuncion] = [];
            }
            $funciones[$nombreFuncion][] = $nombreArchivo;
        }
    }
}

// Filtrar solo las que aparecen en más de un archivo
$duplicadas = array_filter($funciones, function($archivos) {
    return count($archivos) > 1;
});

if (empty($duplicadas)) {
    echo "✅ No se encontraron funciones duplicadas.\n\n";
    echo "Total de funciones únicas detectadas: " . count($funciones) . "\n";
} else {
    echo "⚠️  FUNCIONES DUPLICADAS ENCONTRADAS:\n";
    echo str_repeat('=', 60) . "\n\n";
    foreach ($duplicadas as $nombre => $archivos) {
        echo "🔴 {$nombre}()\n";
        foreach ($archivos as $a) {
            echo "     - {$a}\n";
        }
        echo "\n";
    }
}

// Bonus: listado completo de funciones por archivo
echo "\n" . str_repeat('=', 60) . "\n";
echo "LISTADO COMPLETO DE FUNCIONES POR ARCHIVO\n";
echo str_repeat('=', 60) . "\n\n";

ksort($funciones);
foreach ($funciones as $nombre => $archivos) {
    echo "• {$nombre}() → " . implode(', ', $archivos) . "\n";
}