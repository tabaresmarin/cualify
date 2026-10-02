<?php
/**
 * @var array<int, array<string, mixed>> $leads
 * @var string $busqueda
 * @var string $status
 * @var string $clasificacion
 * @var array<string, string> $etiquetasStatus
 * @var array<string, string> $etiquetasClasif
 * @var array<string, mixed>|null $detalle
 * @var array<int, array<string, mixed>>|null $conversacion
 * @var string $baseUrl
 * @var callable $e
 */

use App\Services\MetricsService;

$etiquetasClasif = $etiquetasClasif ?? MetricsService::etiquetasClasificacion();
?>

<div class="cualify-page-head">
    <div>
        <h1>Leads</h1>
        <p class="cualify-muted"><?= $e(count($leads)) ?> lead(s) en el listado</p>
    </div>
</div>

<div class="cualify-card mb-3">
    <form method="get" action="<?= $e($baseUrl) ?>/leads" class="row g-2 align-items-end">
        <div class="col-12 col-md-5">
            <label class="form-label small" for="q">Buscar</label>
            <input type="search" class="form-control" id="q" name="q"
                   value="<?= $e($busqueda) ?>"
                   placeholder="Nombre, telefono, correo o sitio">
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label small" for="status">Estado</label>
            <select class="form-select" id="status" name="status">
                <option value="">Todos</option>
                <?php foreach ($etiquetasStatus as $clave => $texto): ?>
                    <option value="<?= $e($clave) ?>" <?= $status === $clave ? 'selected' : '' ?>>
                        <?= $e($texto) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-6 col-md-3">
            <label class="form-label small" for="clasificacion">Clasificacion</label>
            <select class="form-select" id="clasificacion" name="clasificacion">
                <option value="">Todas</option>
                <?php foreach ($etiquetasClasif as $clave => $texto): ?>
                    <option value="<?= $e($clave) ?>" <?= $clasificacion === $clave ? 'selected' : '' ?>>
                        <?= $e($texto) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-12 col-md-1 d-grid">
            <button type="submit" class="btn btn-primary">Filtrar</button>
        </div>
    </form>
</div>

<div class="cualify-card">
    <div class="table-responsive">
        <table class="table cualify-table align-middle" id="tablaLeads">
            <thead>
                <tr>
                    <th>Lead</th>
                    <th>Sitio</th>
                    <th>Estado</th>
                    <th>Clasificacion</th>
                    <th>PSI</th>
                    <th>Cita</th>
                    <th>Alta</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($leads)): ?>
                <tr>
                    <td colspan="7" class="text-center cualify-muted py-4">
                        No hay leads que coincidan con el filtro.
                    </td>
                </tr>
            <?php endif; ?>

            <?php foreach ($leads as $lead): ?>
                <?php
                $etiquetaEstado = $etiquetasStatus[$lead['status']] ?? ($lead['status'] ?: 'sin estado');
                $etiquetaClasif = $etiquetasClasif[$lead['clasificacion']] ?? ($lead['clasificacion'] ?: 'sin clasificar');
                $score = $lead['pagespeed_score'];
                ?>
                <tr>
                    <td>
                        <div class="cualify-lead-name"><?= $e($lead['name'] ?: 'Sin nombre') ?></div>
                        <div class="cualify-muted small">
                            <a href="tel:<?= $e($lead['phone']) ?>"><?= $e($lead['phone']) ?></a>
                            <?php if ($lead['email']): ?>
                                &middot; <?= $e($lead['email']) ?>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td class="cualify-url">
                        <?php if ($lead['url_sitio']): ?>
                            <a href="<?= $e($lead['url_sitio']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                                <?= $e($lead['url_sitio']) ?>
                            </a>
                        <?php else: ?>
                            <span class="cualify-muted">sin sitio</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="cualify-badge cualify-badge-<?= $e($lead['status'] ?: 'new') ?>"><?= $e($etiquetaEstado) ?></span></td>
                    <td><?= $e($etiquetaClasif) ?></td>
                    <td>
                        <?php if ($score !== null && (int) $score > 0): ?>
                            <span class="cualify-score cualify-score-<?= (int) $score >= 51 ? 'ok' : 'bad' ?>">
                                <?= $e($score) ?>
                            </span>
                        <?php else: ?>
                            <span class="cualify-muted">s/d</span>
                        <?php endif; ?>
                    </td>
                    <td class="cualify-muted small">
                        <?= $lead['cita_fecha'] ? $e($lead['cita_fecha']) . ' ' . $e($lead['cita_hora'] ?? '') : 's/d' ?>
                    </td>
                    <td class="cualify-muted small"><?= $e(substr((string) $lead['created_at'], 0, 10)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (!empty($detalle)): ?>
    <div class="cualify-card mt-4">
        <div class="cualify-card-head">
            <h2><?= $e($detalle['name'] ?: $detalle['phone']) ?></h2>
            <span class="cualify-muted small">#<?= $e($detalle['id']) ?></span>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <dl class="cualify-dl">
                    <dt>Telefono</dt><dd><?= $e($detalle['phone']) ?></dd>
                    <dt>Correo</dt><dd><?= $e($detalle['email'] ?: 's/d') ?></dd>
                    <dt>Sitio</dt><dd class="cualify-url"><?= $e($detalle['url_sitio'] ?: 's/d') ?></dd>
                    <dt>Servicio de interes</dt><dd><?= $e($detalle['servicio_interes'] ?: 's/d') ?></dd>
                </dl>
            </div>
            <div class="col-12 col-md-6">
                <dl class="cualify-dl">
                    <dt>Estado</dt><dd><?= $e($etiquetasStatus[$detalle['status']] ?? 's/d') ?></dd>
                    <dt>Paso</dt><dd><?= $e($detalle['step'] ?? 's/d') ?></dd>
                    <dt>PageSpeed</dt><dd><?= $e($detalle['pagespeed_score'] ?? 's/d') ?></dd>
                    <dt>Cita</dt><dd><?= $e(($detalle['cita_fecha'] ?? '') . ' ' . ($detalle['cita_hora'] ?? '')) ?: 's/d' ?></dd>
                </dl>
            </div>
        </div>

        <?php if (!empty($conversacion)): ?>
            <hr>
            <h3 class="cualify-subhead">Ultimos mensajes</h3>
            <ul class="cualify-thread">
                <?php foreach (array_slice(array_reverse($conversacion), -20) as $mensaje): ?>
                    <li class="cualify-msg cualify-msg-<?= $e($mensaje['role']) ?>">
                        <span class="cualify-msg-role"><?= $e($mensaje['role']) ?></span>
                        <span class="cualify-msg-body"><?= nl2br($e($mensaje['content'])) ?></span>
                        <span class="cualify-msg-time"><?= $e(substr((string) $mensaje['created_at'], 5, 11)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
<?php endif; ?>
