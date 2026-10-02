<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Models\User;

/**
 * Base de los controladores: resuelve View y expone la URL base del panel.
 */
abstract class Controller
{
    protected View $view;

    protected string $baseUrl;

    public function __construct(protected App $app)
    {
        $this->baseUrl = $app->url();

        $this->view = new View($app->viewPath());
        $this->view->share('app', $app);
        $this->view->share('baseUrl', $this->baseUrl);

        /** @var Auth $auth */
        $auth = $app->make(Auth::class);
        $this->view->share('usuarioActual', $auth->user());
    }

    /** @param array<string, mixed> $datos */
    protected function render(string $vista, array $datos = [], string $layout = 'app'): never
    {
        $session = $this->app->make(Session::class);
        $this->view->share('flash', $session->takeFlash());
        $this->view->share('csrf', $session->token());

        $this->app->make(Response::class)->html($this->view->render($vista, $datos, $layout));
    }

    protected function redirect(string $ruta): never
    {
        $this->app->make(Response::class)->redirect($this->baseUrl . $ruta);
    }

    /**
     * Evita open redirect limitando el destino a rutas internas.
     */
    protected function destinoSeguro(?string $next, string $porDefecto = '/dashboard'): string
    {
        if ($next === null || $next === '') {
            return $porDefecto;
        }

        if (!str_starts_with($next, '/') || str_starts_with($next, '//')) {
            return $porDefecto;
        }

        $prefijo = parse_url((string) $this->baseUrl, PHP_URL_PATH) ?: '';
        $prefijo = $prefijo === '/' ? '' : rtrim($prefijo, '/');

        if ($prefijo !== '' && !str_starts_with($next, $prefijo . '/')) {
            return $porDefecto;
        }

        return substr($next, strlen($prefijo)) ?: '/';
    }

    protected function soloJson(Request $request, Response $response): bool
    {
        return str_contains((string) $request->server['HTTP_ACCEPT'], 'application/json');
    }

    protected function usuarios(): User
    {
        return $this->app->make(User::class);
    }
}
