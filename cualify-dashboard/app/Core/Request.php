<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Representacion inmutable de la peticion HTTP entrante.
 */
final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $server
     * @param array<string, mixed> $cookies
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $server = [],
        public readonly array $cookies = [],
    ) {
    }

    public static function capture(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $uri,
            $_GET,
            $_POST,
            $_SERVER,
            $_COOKIE,
        );
    }

    public function input(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->body[$clave] ?? $this->query[$clave] ?? $porDefecto;
    }

    public function query(string $clave, mixed $porDefecto = null): mixed
    {
        return $this->query[$clave] ?? $porDefecto;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /** Path sin el prefijo base, para el router. */
    public function path(string $prefijo = ''): string
    {
        $path = $this->uri;

        if ($prefijo !== '' && str_starts_with($path, $prefijo)) {
            $path = substr($path, strlen($prefijo));
        }

        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public function ip(): string
    {
        return (string) $this->server['REMOTE_ADDR'];
    }

    public function userAgent(): string
    {
        return substr((string) $this->server['HTTP_USER_AGENT'], 0, 255);
    }
}
