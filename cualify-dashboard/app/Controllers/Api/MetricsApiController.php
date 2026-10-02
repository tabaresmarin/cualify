<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\MetricsService;

final class MetricsApiController extends Controller
{
    /**
     * GET /api/metrics?dias=30
     *
     * Devuelve todo el bloque de KPIs y series en un solo JSON, para que un
     * refresh del dashboard no requiera varias peticiones.
     */
    public function index(Request $request, Response $response, array $params): never
    {
        $dias = (int) $request->query('dias', 30);

        if ($dias < 7 || $dias > 365) {
            $dias = 30;
        }

        /** @var MetricsService $metrics */
        $metrics = $this->app->make(MetricsService::class);

        $response->json($metrics->metricasCompletas($dias));
    }

    /** GET /api/health — sin autenticacion, para monitoreo. */
    public function health(Request $request, Response $response, array $params): never
    {
        $estado = ['ok' => true, 'timestamp' => date('c')];

        try {
            $this->app->make(\App\Core\Database::class)->fetchValue('SELECT 1');
            $estado['db'] = 'ok';
        } catch (\Throwable $e) {
            $estado['ok'] = false;
            $estado['db'] = 'error';
        }

        $response->json($estado, $estado['ok'] ? 200 : 503);
    }
}
