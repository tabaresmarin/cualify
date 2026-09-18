<?php

// Carga la configuración desde variables de entorno o desde un archivo .env.
// Copia .env.example a .env y completa los valores. NUNCA pongas credenciales aquí.

function cargarVariablesEntorno($archivo = null) {
    $archivo = $archivo ?: __DIR__ . '/.env';
    if (!is_file($archivo)) {
        return;
    }
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lineas as $linea) {
        $linea = trim($linea);
        if ($linea === '' || $linea[0] === '#' || $linea[0] === ';') {
            continue;
        }
        $partes = explode('=', $linea, 2);
        if (count($partes) !== 2) {
            continue;
        }
        $clave = trim($partes[0]);
        $valor = trim($partes[1]);
        $dobleComilla = $valor[0] === '"' && substr($valor, -1) === '"';
        $simpleComilla = $valor[0] === "'" && substr($valor, -1) === "'";
        if (($dobleComilla || $simpleComilla) && strlen($valor) >= 2) {
            $valor = substr($valor, 1, -1);
        }
        if (getenv($clave) !== false) {
            continue; // El entorno real tiene prioridad sobre .env
        }
        putenv("$clave=$valor");
        $_ENV[$clave] = $valor;
        $_SERVER[$clave] = $valor;
    }
}

function valorEntorno($clave) {
    $valor = getenv($clave);
    if ($valor !== false) {
        return $valor;
    }
    return isset($_ENV[$clave]) ? $_ENV[$clave] : null;
}

cargarVariablesEntorno();

$variablesRequeridas = [
    'WA_VERIFY_TOKEN',
    'WA_PHONE_NUMBER_ID',
    'WA_ACCESS_TOKEN',
    'DB_HOST',
    'DB_USER',
    'DB_PASS',
    'DB_NAME',
];

foreach ($variablesRequeridas as $variable) {
    $valor = valorEntorno($variable);
    if ($valor === null) {
        http_response_code(500);
        echo "Falta la variable de entorno: $variable. Copia .env.example a .env y configúralo.";
        exit(1);
    }
    if (!defined($variable)) {
        define($variable, $valor);
    }
}

// Opcional: App Secret de Meta. Si está definido, webhook.php valida la firma
// X-Hub-Signature-256 de cada solicitud POST (HMAC-SHA256 del body).
$appSecret = valorEntorno('WA_APP_SECRET');
if ($appSecret !== null && $appSecret !== '' && !defined('WA_APP_SECRET')) {
    define('WA_APP_SECRET', $appSecret);
}