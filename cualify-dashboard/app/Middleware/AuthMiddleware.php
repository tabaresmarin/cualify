<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Exige sesion iniciada. Las rutas de API devuelven 401 en JSON; el resto
 * redirige al login conservando la URL de destino.
 */
final class AuthMiddleware
{
    public function handle(Request $request, Response $response, App $app, array $params = []): bool
    {
        /** @var Auth $auth */
        $auth = $app->make(Auth::class);

        if ($auth->check()) {
            return true;
        }

        if (str_starts_with($request->path($this->prefijo($app)), '/api')) {
            $response->json(['error' => 'No autenticado'], 401);
            return false;
        }

        $destino = $request->uri;
        $base = $app->url();

        $response->redirect($base . '/login?next=' . rawurlencode($destino));
        return false;
    }

    private function prefijo(App $app): string
    {
        return $app->pathPrefix();
    }
}
