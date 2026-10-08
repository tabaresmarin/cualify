<?php

declare(strict_types=1);

use App\Core\Router;
use App\Controllers\Api\MetricsApiController;
use App\Controllers\AuthController;
use App\Controllers\ClientsController;
use App\Controllers\ConversationsController;
use App\Controllers\DashboardController;
use App\Controllers\LeadsController;
use App\Controllers\OutreachController;
use App\Controllers\PagespeedController;
use App\Middleware\AuthMiddleware;

/** @var Router $router */

$router->get('/login', [AuthController::class, 'mostrarLogin']);
$router->post('/login', [AuthController::class, 'login']);

$router->get('/api/health', [MetricsApiController::class, 'health']);

$router->group([AuthMiddleware::class], function (Router $router): void {
    $router->get('/', [DashboardController::class, 'index']);
    $router->get('/dashboard', [DashboardController::class, 'index']);

    $router->get('/leads', [LeadsController::class, 'index']);
    $router->get('/leads/{id}', [LeadsController::class, 'show']);

    $router->get('/conversations', [ConversationsController::class, 'index']);

    $router->get('/pagespeed', [PagespeedController::class, 'index']);

    $router->get('/clients', [ClientsController::class, 'index']);
    $router->get('/clients/crear', [ClientsController::class, 'crear']);
    $router->post('/clients/crear', [ClientsController::class, 'guardar']);
    $router->get('/clients/{id}/editar', [ClientsController::class, 'editar']);
    $router->post('/clients/{id}/editar', [ClientsController::class, 'actualizar']);

    $router->get('/outreach', [OutreachController::class, 'index']);
    $router->post('/outreach/enviar', [OutreachController::class, 'enviar']);

    // Solo POST: el logout va por formulario con token CSRF, para que nadie
    // pueda cerrar la sesion de otro con un simple enlace GET.
    $router->post('/logout', [AuthController::class, 'logout']);

    $router->get('/api/metrics', [MetricsApiController::class, 'index']);
});
