<?php
/**
 * @var array<string, mixed> $resumen
 * @var array<int, array<string, mixed>> $leadsPorDia
 * @var array<string, int> $porStatus
 * @var array<int, array<string, mixed>> $distribucionPsi
 * @var array<int, array<string, mixed>> $citas
 * @var array<int, array<string, mixed>> $atascados
 * @var array<int, array<string, mixed>> $topLenguaje
 * @var callable $e
 */

use App\Services\MetricsService;

$etiquetas = MetricsService::etiquetasStatus();
$etiquetasClasif = MetricsService::etiquetasClasificacion();

$etiquetasStatusGrafico = [];
$datosStatusGrafico = [];
foreach ($porStatus as $clave => $total) {
    $etiquetasStatusGrafico[] = $etiquetas[$clave] ?? $clave;
    $datosStatusGrafico[] = $total;
}

$etiquetasDia = array_column($leadsPorDia, 'etiqueta');
$datosDia = array_column($leadsPorDia, 'total');

$cola = $resumen['cola'];
$promedio = $resumen['promedio_pagespeed'];
?>

<div class="cualify-page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="cualify-muted">Resumen del embudo y del estado del bot</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Leads totales',    (int) $resumen['leads_total'],       'bi-people',            'text-primary'],
        ['Ultimos 7 dias',   (int) $resumen['leads_semana'],      'bi-graph-up-arrow',    'text-success'],
        ['En calificacion',  (int) $resumen['en_calificacion'],   'bi-hourglass-split',   'text-warning'],
        ['Requieren asesor', (int) $resumen['requieren_humano'],  'bi-person-exclamation', 'text-danger'],
        ['Citas agendadas',  (int) $resumen['citas_agendadas'],   'bi-calendar-check',    'text-info'],
        ['PageSpeed medio',  $promedio === null ? 's/d' : $promedio, 'bi-speedometer2',    'text-secondary'],
    ];
    foreach ($kpis as [$etiqueta, $valor, $icono, $color]): ?>
        <div class="col-12 col-sm-6 col-xl-2">
            <div class="cualify-kpi">
                <div class="cualify-kpi-icon <?= $e($color) ?>">
                    <i class="bi <?= $e($icono) ?>"></i>
                </div>
                <div>
                    <div class="cualify-kpi-value"><?= $e($valor) ?></div>
                    <div class="cualify-kpi-label"><?= $e($etiqueta) ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-8">
        <div class="cualify-card">
            <div class="cualify-card-head">
                <h2>Leads por dia</h2>
                <span class="cualify-muted small">Ultimos 30 dias</span>
            </div>
            <div class="cualify-chart">
                <canvas id="chartLeads"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-4">
        <div class="cualify-card">
            <div class="cualify-card-head"><h2>Estado del embudo</h2></div>
            <div class="cualify-chart">
                <canvas id="chartStatus"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-6">
        <div class="cualify-card">
            <div class="cualify-card-head"><h2>Rendimiento de sitios</h2></div>
            <div class="cualify-chart">
                <canvas id="chartPageSpeed"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-6">
        <div class="cualify-card">
            <div class="cualify-card-head">
                <h2>Cola de trabajos</h2>
                <span class="cualify-muted small">
                    <?= $e($cola['promedio_segundos'] === null ? 'sin datos de tiempo' : $cola['promedio_segundos'] . ' s promedio') ?>
                </span>
            </div>
            <div class="cualify-queue">
                <?php
                $estadosCola = [
                    'Pendientes'  => ['pending',    'warning'],
                    'Procesando'  => ['processing', 'info'],
                    'Completados' => ['done',       'success'],
                    'Fallidos'    => ['failed',     'danger'],
                ];
                foreach ($estadosCola as $texto => [$clave, $color]): ?>
                    <div class="cualify-queue-item">
                        <span class="cualify-queue-dot text-<?= $e($color) ?>"></span>
                        <span class="cualify-queue-label"><?= $e($texto) ?></span>
                        <span class="cualify-queue-value"><?= $e((int) ($cola[$clave] ?? 0)) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr>

            <h3 class="cualify-subhead">Servicios de interes</h3>
            <?php if (empty($topLenguaje)): ?>
                <p class="cualify-muted small">Sin datos de servicios registrados.</p>
            <?php else: ?>
                <div class="cualify-tags">
                    <?php foreach ($topLenguaje as $fila): ?>
                        <span class="cualify-tag">
                            <?= $e($fila['servicio']) ?>
                            <b><?= $e((int) $fila['total']) ?></b>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-xl-7">
        <div class="cualify-card">
            <div class="cualify-card-head"><h2>Proximas citas</h2></div>

            <?php if (empty($citas)): ?>
                <p class="cualify-muted">No hay citas agendadas.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table cualify-table mb-0">
                        <thead>
                            <tr>
                                <th>Fecha</th><th>Hora</th><th>Lead</th><th>Sitio</th><th>PSI</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($citas as $cita): ?>
                            <tr>
                                <td><?= $e($cita['cita_fecha']) ?></td>
                                <td><?= $e($cita['cita_hora']) ?></td>
                                <td><?= $e($cita['name'] ?: $cita['phone']) ?></td>
                                <td class="text-truncate cualify-url"><?= $e($cita['url_sitio'] ?: 's/d') ?></td>
                                <td><?= $cita['pagespeed_score'] !== null ? $e($cita['pagespeed_score']) : 's/d' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-xl-5">
        <div class="cualify-card">
            <div class="cualify-card-head"><h2>Jobs atascados</h2></div>

            <?php if (empty($atascados)): ?>
                <p class="cualify-muted">Nada atascado. La cola esta limpia.</p>
            <?php else: ?>
                <div class="alert alert-warning small">
                    Hay <?= $e(count($atascados)) ?> job(s) en estado <code>processing</code> hace mas de 10 minutos.
                    Suele significar que un worker murio a mitad del trabajo.
                </div>
                <ul class="cualify-list">
                    <?php foreach ($atascados as $job): ?>
                        <li>
                            <strong>#<?= $e($job['id']) ?></strong>
                            <span class="cualify-muted"><?= $e($job['job_type']) ?></span>
                            <span class="cualify-muted">intento <?= $e($job['attempts']) ?></span>
                            <span class="text-warning"><?= $e($job['minutos']) ?> min</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
window.CUALIFY_DASHBOARD = {
    etiquetasDia: <?= json_encode($etiquetasDia, JSON_UNESCAPED_UNICODE) ?>,
    datosDia: <?= json_encode($datosDia) ?>,
    etiquetasStatus: <?= json_encode($etiquetasStatusGrafico, JSON_UNESCAPED_UNICODE) ?>,
    datosStatus: <?= json_encode($datosStatusGrafico) ?>,
    distribucionPsi: <?= json_encode(array_column($distribucionPsi, 'etiqueta'), JSON_UNESCAPED_UNICODE) ?>,
    datosPsi: <?= json_encode(array_map('intval', array_column($distribucionPsi, 'total'))) ?>
};
</script>
