<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Renderizador de vistas con layouts y "partials" mediante @include parcial
 * dentro de las plantillas.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $compartidos = [];

    public function __construct(private string $rutaBase)
    {
    }

    public function share(string $clave, mixed $valor): void
    {
        $this->compartidos[$clave] = $valor;
    }

    /** @param array<string, mixed> $datos */
    public function render(string $vista, array $datos = [], string $layout = 'app'): string
    {
        $contenido = $this->capturar($vista, $datos);

        if ($layout === '') {
            return $contenido;
        }

        return $this->capturar('layouts/' . $layout, array_merge($datos, ['contenido' => $contenido]));
    }

    /** @param array<string, mixed> $datos */
    public function parcial(string $parcial, array $datos = []): string
    {
        return $this->capturar('partials/' . $parcial, $datos);
    }

    /** @param array<string, mixed> $datos */
    private function capturar(string $vista, array $datos): string
    {
        $archivo = $this->rutaBase . '/' . str_replace('.', '/', $vista) . '.php';

        if (!is_file($archivo)) {
            throw new \RuntimeException("No se encontro la vista: {$vista}");
        }

        $ambito = array_merge($this->compartidos, $datos);
        $ambito['vista'] = $vista;
        $ambito['e'] = $this->escape(...);

        // El closure NO es estatico a proposito: las plantillas usan $this->parcial().
        $render = function (string $__archivo, array $__ambito): void {
            extract($__ambito, EXTR_SKIP);
            require $__archivo;
        };

        ob_start();
        $render($archivo, $ambito);
        return (string) ob_get_clean();
    }

    public function escape(mixed $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
