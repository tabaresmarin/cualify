<?php

declare(strict_types=1);

/**
 * Crea o actualiza un usuario del panel. Uso (solo CLI):
 *
 *   php bin/crear_usuario.php "Nombre Apellido" "correo@eiso.com.co" "contrasena" [admin|viewer]
 *
 * Si el correo ya existe, actualiza nombre, contrasena, rol y estado en lugar
 * de fallar: asi sirve tambien para recuperar el acceso.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por linea de comandos.\n");
}

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\App;
use App\Models\User;

$base = dirname(__DIR__);
App::getInstance()->bootstrap($base);

$args = array_slice($argv, 1);

if (count($args) < 3) {
    fwrite(STDERR, "Uso: php bin/crear_usuario.php \"Nombre\" \"correo\" \"contrasena\" [admin|viewer]\n");
    exit(1);
}

[$nombre, $email, $password] = [$args[0], $args[1], $args[2]];
$rol = $args[3] ?? 'admin';

if (!in_array($rol, User::ROLES, true)) {
    fwrite(STDERR, "El rol debe ser 'admin' o 'viewer'.\n");
    exit(1);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "El correo no es valido: {$email}\n");
    exit(1);
}

if (mb_strlen($password) < 8) {
    fwrite(STDERR, "La contrasena debe tener al menos 8 caracteres.\n");
    exit(1);
}

/** @var User $usuarios */
$usuarios = App::getInstance()->make(User::class);
$existente = $usuarios->findByEmail($email);

try {
    if ($existente !== null) {
        $app = App::getInstance();
        $app->make(\App\Core\Database::class)->execute(
            'UPDATE users SET name = ?, password = ?, role = ? WHERE id = ?',
            [$nombre, password_hash($password, PASSWORD_DEFAULT), $rol, (int) $existente['id']]
        );

        echo "Usuario #{$existente['id']} ({$email}) actualizado. Rol: {$rol}.\n";
        exit(0);
    }

    $id = $usuarios->registrar($nombre, $email, $password, $rol);
    echo "Usuario creado con id {$id}: {$email} (rol {$rol}).\n";
    exit(0);
} catch (PDOException $e) {
    fwrite(STDERR, "Error de base de datos: " . $e->getMessage() . "\n");
    exit(1);
}
