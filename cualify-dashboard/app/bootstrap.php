<?php

declare(strict_types=1);

/**
 * Front controller del panel Cualify.
 *
 * Esta fuera del docroot logico de la app: el docroot real es public/, asi que
 * este archivo solo debe cargarse a traves de public/index.php.
 */

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Bogota');

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::getInstance()->bootstrap(dirname(__DIR__));

/** @var Request $request */
$request = $app->make(Request::class);
/** @var Response $response */
$response = $app->make(Response::class);
/** @var Router $router */
$router = $app->make(Router::class);

require $app->basePath('routes/web.php');

// Sesion siempre activa: la usan el login, el CSRF y los mensajes flash.
// En CLI no se inicia, asi que los scripts de linea de comandos no se rompen.
if (PHP_SAPI !== 'cli') {
    $app->make(Session::class)->start();
}

// Manejo de errores: si algo revienta dentro de una vista, se muestra una
// pagina de error en lugar del warning crudo de PHP.
set_exception_handler(static function (Throwable $e) use ($response, $app): void {
    error_log('[cualify-dashboard] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

    $debug = (bool) $app->config('debug', false);

    if ($debug) {
        $response->html(
            '<pre style="padding:24px;font:13px/1.5 monospace;white-space:pre-wrap">'
            . htmlspecialchars(
                $e::class . ': ' . $e->getMessage() . "\n\n"
                . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString(),
                ENT_QUOTES,
                'UTF-8'
            )
            . '</pre>',
            500
        );
    }

    $response->html(
        '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        . '<title>Error interno</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">'
        . '</head><body class="bg-light"><div class="container py-5">'
        . '<div class="card shadow-sm"><div class="card-body p-5 text-center">'
        . '<h1 class="h4">Algo salio mal</h1>'
        . '<p class="text-muted mb-0">El panel no pudo completar la peticion. El error quedo registrado.</p>'
        . '</div></div></div></body></html>',
        500
    );
});

$router->dispatch($request, $app, $response);
