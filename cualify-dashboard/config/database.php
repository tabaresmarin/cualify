<?php

declare(strict_types=1);

/**
 * Credenciales de la base de datos.
 *
 * NO escribas la contraseña aquí: este archivo se versiona en git. Va en
 * .env (ignorado por .gitignore), que App::loadEnv() carga antes de leer este
 * config y por lo tanto tiene prioridad sobre los valores de abajo.
 */

return [
    'host'    => App\Core\App::env('DB_HOST', 'localhost'),
    'port'    => (int) App\Core\App::env('DB_PORT', 3306),
    'name'    => App\Core\App::env('DB_NAME', 'db_cualify'),
    'user'    => App\Core\App::env('DB_USER', 'root'),
    'pass'    => App\Core\App::env('DB_PASS', ''),
    'charset' => 'utf8mb4',
];
