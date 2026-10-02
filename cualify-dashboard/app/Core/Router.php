<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router con soporte de middleware y parametros nombrados en la ruta.
 */
final class Router
{
    /** @var array<int, array{method: string, regex: string, params: array<int,string>, handler: array, middleware: array<int,string>}> */
    private array $routes = [];

    /** @var array<int, string> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    public function get(string $path, array $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    /** @param array<int, string> $middleware */
    public function group(array $middleware, \Closure $callback): void
    {
        $previos = $this->groupMiddleware;
        $prefijoPrevio = $this->groupPrefix;

        $this->groupMiddleware = array_merge($previos, $middleware);
        $callback($this);

        $this->groupMiddleware = $previos;
        $this->groupPrefix = $prefijoPrevio;
    }

    public function prefix(string $prefijo): void
    {
        $this->groupPrefix = rtrim($prefijo, '/');
    }

    /** @param array<int, string> $middleware */
    private function add(string $method, string $path, array $handler, array $middleware): void
    {
        $path = $this->groupPrefix . $path;
        $path = '/' . ltrim($path, '/');

        [$regex, $params] = $this->compile($path);

        $this->routes[] = [
            'method'     => $method,
            'regex'      => $regex,
            'params'     => $params,
            'handler'    => $handler,
            'middleware' => array_merge($this->groupMiddleware, $middleware),
        ];
    }

    /** @return array{0: string, 1: array<int, string>} */
    private function compile(string $path): array
    {
        $params = [];

        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            static function (array $m) use (&$params): string {
                $params[] = $m[1];
                return '([^/]+)';
            },
            $path
        );

        return ['#^' . $regex . '$#', $params];
    }

    /**
     * Resuelve y ejecuta la ruta que corresponde a la peticion.
     */
    public function dispatch(Request $request, App $app, Response $response): never
    {
        $path = $request->path($this->basePathFromUrl($app));

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            if (!preg_match($route['regex'], $path, $coincidencias)) {
                continue;
            }

            array_shift($coincidencias);

            $params = [];
            foreach ($route['params'] as $indice => $nombre) {
                $params[$nombre] = urldecode($coincidencias[$indice] ?? '');
            }

            foreach ($route['middleware'] as $middleware) {
                $instancia = new $middleware();

                if ($instancia->handle($request, $response, $app, $params) === false) {
                    exit;
                }
            }

            [$clase, $accion] = $route['handler'];
            $controlador = new $clase($app);

            $resultado = $controlador->{$accion}($request, $response, $params);

            if ($resultado instanceof Response) {
                $resultado->send();
            }

            $response->html('Ruta ejecutada sin respuesta.');
        }

        $response->html(
            '<h1>404</h1><p>La ruta <code>' . htmlspecialchars($path, ENT_QUOTES, 'UTF-8')
            . '</code> no existe.</p>',
            404
        );
    }

    /**
     * Detecta el prefijo del panel a partir de la ruta real del script, para que
     * el router funcione tanto en la raiz del dominio como en un subdirectorio.
     */
    private function basePathFromUrl(App $app): string
    {
        return $app->pathPrefix();
    }
}
