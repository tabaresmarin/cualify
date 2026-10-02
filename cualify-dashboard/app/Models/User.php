<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class User extends Model
{
    protected string $table = 'users';

    /**
     * Roles admitidos por el enum de `users.role` del schema autoritativo.
     */
    public const ROLES = ['admin', 'viewer'];

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE email = ? LIMIT 1',
            [mb_strtolower(trim($email))]
        );
    }

    /**
     * El schema no tiene columna `is_active`: toda fila de `users` es usable.
     * El filtro por rol se hace en la interfaz, no en SQL.
     *
     * @return array<string, mixed>|null
     */
    public function findActive(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    public function updatePassword(int $id, string $passwordPlano): void
    {
        $this->db->execute(
            'UPDATE users SET password = ? WHERE id = ?',
            [password_hash($passwordPlano, PASSWORD_DEFAULT), $id]
        );
    }

    public function touchLastLogin(int $id): void
    {
        $this->db->execute(
            'UPDATE users SET last_login = NOW() WHERE id = ?',
            [$id]
        );
    }

    public function registrar(string $nombre, string $email, string $password, string $rol = 'admin'): int
    {
        if (!in_array($rol, self::ROLES, true)) {
            throw new \InvalidArgumentException(
                'Rol invalido: usa ' . implode(' o ', self::ROLES) . '.'
            );
        }

        $this->db->execute(
            'INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)',
            [$nombre, mb_strtolower(trim($email)), password_hash($password, PASSWORD_DEFAULT), $rol]
        );

        return $this->db->lastInsertId();
    }

    // ------------------------------------------------------------------
    // Control de intentos de login (anti fuerza bruta)
    //
    // `login_attempts` NO forma parte del schema del bot (funciona.sql): es una
    // tabla opcional del panel, creada por la migracion 001. Si no existe, el
    // limite de intentos simplemente se desactiva en vez de romper el login.
    // ------------------------------------------------------------------

    public function tieneLoginAttempts(): bool
    {
        return $this->db->tableExists('login_attempts');
    }

    public function intentosFallidos(string $email, int $maxIntentos, int $segundosBloqueo): int
    {
        $intentos = $this->db->fetchAllSiExiste(
            'SELECT attempted_at FROM login_attempts
             WHERE email = ? AND successful = 0 AND attempted_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             ORDER BY attempted_at DESC',
            [mb_strtolower(trim($email)), $segundosBloqueo]
        );

        return count($intentos);
    }

    public function registrarIntento(string $email, string $ip, bool $exitoso): void
    {
        if (!$this->tieneLoginAttempts()) {
            return;
        }

        $this->db->execute(
            'INSERT INTO login_attempts (email, ip_address, successful, attempted_at)
             VALUES (?, ?, ?, NOW())',
            [mb_strtolower(trim($email)), $ip, $exitoso ? 1 : 0]
        );

        if ($exitoso) {
            $this->db->execute(
                'DELETE FROM login_attempts WHERE email = ? AND successful = 0',
                [mb_strtolower(trim($email))]
            );
        }
    }

    public function limpiarIntentosVencidos(int $segundos): int
    {
        if (!$this->tieneLoginAttempts()) {
            return 0;
        }

        $stmt = $this->db->query(
            'DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            [$segundos * 4]
        );

        return $stmt->rowCount();
    }
}
