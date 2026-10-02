<?php
/**
 * @var array<int, array<string, mixed>> $analisis
 * @var array<int, array<string, mixed>> $distribucion
 * @var float|null $promedio
 * @var int $totalSitios
 * @var callable $e
 */

$colores = ['#dc3545', '#fd7e14', '#198754', '#adb5bd'];
?>

<div class="cualify-page-head">
    <div>
        <h1>Rendimiento</h1>
        <p class="cualify-muted">Analisis PSI guardados por el bot (cache de 24 h)</p>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-md-4">
        <div class="cualify-card">
            <div class="cualify-muted small">Sitios analizados</div>
            <div class="cualify-big"><?= $e($totalSitios) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="cualify-card">
            <div class="cualify-muted small">Puntaje promedio</div>
            <div class="cualify-big"><?= $e($promedio ?? 's/d') ?></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="cualify-card">
            <div class="cualify-muted small">Listos para marketing</div>
            <div class="cualify-big"><?= $e((int) ($distribucion[2]['total'] ?? 0)) ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12 col-xl-5">
        <div class="cualify-card">
            <div class="cualify-card-head"><h2>Distribucion</h2></div>
            <div class="cualify-chart">
                <canvas id="chartDistribucion"></canvas>
            </div>
        </div>
    </div>

    <div class="col-12 col-xl-7">
        <div class="cualify-card">
            <div class="cualify-card-head">
                <h2>Ultimos analisis</h2>
                <span class="cualify-muted small"><?= $e(count($analisis)) ?> registro(s)</span>
            </div>

            <?php if (empty($analisis)): ?>
                <p class="cualify-muted mb-0">Todavia no hay analisis en la cache.</p>
            <?php else: ?>
                <div class="cualify-scroll">
                    <table class="table cualify-table align-middle mb-0">
                        <thead>
                            <tr><th>URL</th><th>Estrategia</th><th>Score</th><th>Analizado</th><th>Vence</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($analisis as $fila): ?>
                            <?php $score = (int) ($fila['score'] ?? 0); ?>
                            <tr>
                                <td class="cualify-url">
                                    <a href="<?= $e($fila['url']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                                        <?= $e($fila['url']) ?>
                                    </a>
                                </td>
                                <td class="cualify-muted small"><?= $e($fila['strategy']) ?></td>
                                <td>
                                    <span class="cualify-score cualify-score-<?= $score >= 51 ? 'ok' : 'bad' ?>">
                                        <?= $e($score ?: 's/d') ?>
                                    </span>
                                </td>
                                <td class="cualify-muted small"><?= $e(substr((string) $fila['created_at'], 5, 11)) ?></td>
                                <td class="cualify-muted small"><?= $e(substr((string) $fila['expires_at'], 5, 11)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="cualify-card">
    <div class="cualify-card-head"><h2>Detalle por categoria</h2></div>
    <div class="table-responsive">
        <table class="table cualify-table mb-0">
            <thead><tr><th>Categoria</th><th>Leads</th></tr></thead>
            <tbody>
            <?php foreach ($distribucion as $fila): ?>
                <tr>
                    <td><?= $e($fila['etiqueta']) ?></td>
                    <td><?= $e((int) $fila['total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
window.CUALIFY_PAGESPEED = {
    etiquetas: <?= json_encode(array_column($distribucion, 'etiqueta'), JSON_UNESCAPED_UNICODE) ?>,
    datos: <?= json_encode(array_map('intval', array_column($distribucion, 'total'))) ?>,
    colores: <?= json_encode($colores) ?>
};
</script>
