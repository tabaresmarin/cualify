<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Models\Lead;
use App\Services\MetricsService;

final class PagespeedController extends Controller
{
    public function index(Request $request, Response $response, array $params): never
    {
        /** @var Lead $leads */
        $leads = $this->app->make(Lead::class);
        /** @var MetricsService $metrics */
        $metrics = $this->app->make(MetricsService::class);

        $minimo = max(0, min(100, (int) $request->query('min', 0)));

        $this->render('pagespeed.index', [
            'titulo'        => 'Rendimiento',
            'analisis'      => $leads->analisisPagespeed(500),
            'distribucion'  => $metrics->distribucionPageSpeed(),
            'promedio'      => $metrics->promedioPageSpeed(),
            'totalSitios'   => $metrics->conteoCache(),
            'minimo'        => $minimo,
        ]);
    }
}
