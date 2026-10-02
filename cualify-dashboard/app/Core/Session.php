<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Sesion con clave de CSRF por token y mensajes flash de un solo uso.
 */
final class Session
{
    private bool $iniciada = false;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config = [])
    {
    }

    public function start(): void
    {
        if ($this->iniciada || PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
            $this->iniciada = true;
            return;
        }

        session_name((string) ($this->config['name'] ?? 'cualify_dashboard_session'));

        session_set_cookie_params([
            'lifetime' => (int) ($this->config['lifetime'] ?? 7200),
            'path'     => '/',
            'domain'   => '',
            'secure'   => (bool) ($this->config['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
        $this->iniciada = true;

        if (!isset($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
    }

    public function get(string $clave, mixed $porDefecto = null): mixed
    {
        $this->start();
        return $_SESSION[$clave] ?? $porDefecto;
    }

    public function put(string $clave, mixed $valor): void
    {
        $this->start();
        $_SESSION[$clave] = $valor;
    }

    public function forget(string $clave): void
    {
        $this->start();
        unset($_SESSION[$clave]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];

        if (!headers_sent() && ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        session_destroy();
        $this->iniciada = false;
    }

    public function token(): string
    {
        $this->start();
        return (string) $_SESSION['_csrf'];
    }

    public function verifyToken(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals($this->token(), $token);
    }

    public function flash(string $tipo, string $mensaje): void
    {
        $this->start();
        $_SESSION['_flash'][] = ['tipo' => $tipo, 'mensaje' => $mensaje];
    }

    /** @return array<int, array{tipo: string, mensaje: string}> */
    public function takeFlash(): array
    {
        $this->start();
        $mensajes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $mensajes;
    }
}
