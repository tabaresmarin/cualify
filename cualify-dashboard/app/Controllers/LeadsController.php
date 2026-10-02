<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Lead;
use App\Services\MetricsService;

final class LeadsController extends Controller
{
    public function index(Request $request, Response $response, array $params): never
    {
        /** @var Lead $leads */
        $leads = $this->app->make(Lead::class);

        $busqueda = trim((string) $request->query('q', ''));
        $status   = trim((string) $request->query('status', ''));
        $clasif   = trim((string) $request->query('clasificacion', ''));

        $filtros = [];

        if ($busqueda !== '') {
            $filtros['q'] = $busqueda;
        }

        if ($status !== '' && array_key_exists($status, MetricsService::etiquetasStatus())) {
            $filtros['status'] = $status;
        }

        if ($clasif !== '') {
            $filtros['clasificacion'] = $clasif;
        }

        $this->render('leads.index', [
            'titulo'      => 'Leads',
            'leads'       => $leads->listar($filtros, 500),
            'busqueda'    => $busqueda,
            'status'      => $status,
            'clasificacion' => $clasif,
            'etiquetasStatus' => MetricsService::etiquetasStatus(),
            'etiquetasClasif' => MetricsService::etiquetasClasificacion(),
        ]);
    }

    public function show(Request $request, Response $response, array $params): never
    {
        /** @var Lead $leads */
        $leads = $this->app->make(Lead::class);

        $lead = $leads->detalle((int) ($params['id'] ?? 0));

        if ($lead === null) {
            $this->app->make(Session::class)->flash('error', 'Ese lead no existe.');
            $this->redirect('/leads');
        }

        $this->render('leads.index', [
            'titulo'      => 'Leads',
            'leads'       => $leads->listar([], 500),
            'busqueda'    => '',
            'status'      => '',
            'clasificacion' => '',
            'etiquetasStatus' => MetricsService::etiquetasStatus(),
            'etiquetasClasif' => MetricsService::etiquetasClasificacion(),
            'detalle'     => $lead,
            'conversacion' => $leads->conversacion((string) $lead['phone'], 200),
        ]);
    }
}
