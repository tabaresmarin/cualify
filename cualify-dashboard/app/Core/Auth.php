<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autenticacion por sesion contra la tabla `users`.
 */
final class Auth
{
    private ?array $usuario = null;

    private bool $resuelto = false;

    public function __construct(
        private \App\Models\User $usuarios,
        private Session $session,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->resuelto) {
            return $this->usuario;
        }

        $this->resuelto = true;
        $id = (int) $this->session->get('user_id');

        if ($id <= 0) {
            return null;
        }

        $usuario = $this->usuarios->findActive($id);

        if ($usuario === null) {
            $this->session->forget('user_id');
            return null;
        }

        $this->usuario = $usuario;
        return $this->usuario;
    }

    public function id(): ?int
    {
        return $this->user()['id'] ?? null;
    }

    public function attempt(string $email, string $password): bool
    {
        $usuario = $this->usuarios->findByEmail($email);

        if ($usuario === null) {
            return false;
        }

        // El hash vive en `users.password` (no `password_hash`) en el schema
        // autoritativo.
        $hash = (string) ($usuario['password'] ?? '');

        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->usuarios->updatePassword((int) $usuario['id'], $password);
        }

        $this->usuarios->touchLastLogin((int) $usuario['id']);

        $session = $this->session;
        $session->regenerate();
        $session->put('user_id', (int) $usuario['id']);

        $this->usuario = $usuario;
        $this->resuelto = true;

        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->usuario = null;
        $this->resuelto = true;
    }
}
