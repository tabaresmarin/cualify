<?php

declare(strict_types=1);

return [
    'name'     => 'Panel Cualify',
    'env'      => App\Core\App::env('APP_ENV', 'production'),
    'debug'    => App\Core\App::env('APP_DEBUG', false) === true
                  || App\Core\App::env('APP_DEBUG', 'false') === 'true',
    'url'      => rtrim((string) App\Core\App::env('APP_URL', 'http://localhost:8000'), '/'),
    'timezone' => App\Core\App::env('APP_TIMEZONE', 'America/Bogota'),

    // Rutas absolutas, derivadas de la ubicacion de este archivo.
    'base_path' => dirname(__DIR__),
    'view_path' => dirname(__DIR__) . '/app/Views',

    'session' => [
        'name'     => 'cualify_dashboard_session',
        'lifetime' => 7200,
        'secure'   => App\Core\App::env('APP_ENV', 'production') === 'production',
    ],

    // Limite de intentos de login antes de bloquear temporalmente.
    'auth' => [
        'max_attempts'    => 5,
        'lockout_seconds' => 900,
    ],
];
