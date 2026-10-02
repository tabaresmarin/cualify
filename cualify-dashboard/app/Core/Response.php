<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Envoltura de la respuesta HTTP. Los metodos terminan la ejecucion.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    public function __construct(
        private int $status = 200,
        private array $cookies = [],
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    public function header(string $nombre, string $valor): self
    {
        $this->headers[$nombre] = $valor;
        return $this;
    }

    public function cookie(string $nombre, string $valor, array $opciones = []): self
    {
        $this->cookies[] = array_merge([
            'expires'  => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ], $opciones, ['name' => $nombre, 'value' => $valor]);

        return $this;
    }

    public function send(): never
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $nombre => $valor) {
                header("$nombre: $valor");
            }

            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], [
                    'expires'  => $cookie['expires'],
                    'path'     => $cookie['path'],
                    'domain'   => $cookie['domain'],
                    'secure'   => $cookie['secure'],
                    'httponly' => $cookie['httponly'],
                    'samesite' => $cookie['samesite'],
                ]);
            }
        }

        echo $this->body;
        exit;
    }

    public function html(string $html, int $status = 200): never
    {
        $this->status = $status;
        $this->body = $html;
        $this->header('Content-Type', 'text/html; charset=utf-8');
        $this->send();
    }

    public function json(mixed $datos, int $status = 200): never
    {
        $this->status = $status;
        $this->body = json_encode(
            $datos,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
        ) ?: '{"error":"fallo al serializar"}';
        $this->header('Content-Type', 'application/json; charset=utf-8');
        $this->header('X-Content-Type-Options', 'nosniff');
        $this->send();
    }

    public function redirect(string $url, int $status = 302): never
    {
        $this->status = $status;
        $this->header('Location', $url);
        $this->send();
    }
}
