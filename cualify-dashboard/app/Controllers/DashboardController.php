<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Services\MetricsService;

final class DashboardController extends Controller
{
    public function index(Request $request, Response $response, array $params): never
    {
        /** @var MetricsService $metrics */
        $metrics = $this->app->make(MetricsService::class);
        $db = $this->app->make(Database::class);

        $this->render('dashboard.index', [
            'titulo'          => 'Dashboard',
            'resumen'         => $metrics->resumen(),
            'leadsPorDia'     => $metrics->leadsPorDia(30),
            'porStatus'       => $metrics->leadsPorStatus(),
            'distribucionPsi' => $metrics->distribucionPageSpeed(),
            'citas'           => $metrics->citasProximas(6),
            'atascados'       => $metrics->jobsAtascados(10),
            'topLenguaje'     => $db->fetchAll(
                "SELECT COALESCE(NULLIF(servicio_interes, ''), 'sin_registrar') AS servicio,
                        COUNT(*) AS total
                 FROM leads GROUP BY servicio ORDER BY total DESC LIMIT 6"
            ),
        ]);
    }
}
