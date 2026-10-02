<?php
/**
 * @var array<int, array<string, mixed>> $telefonos
 * @var string $telefono
 * @var array<int, array<string, mixed>> $conversacion
 * @var array<string, mixed>|null $lead
 * @var string $baseUrl
 * @var callable $e
 */

use App\Services\MetricsService;

$etiquetasStatus = MetricsService::etiquetasStatus();
?>

<div class="cualify-page-head">
    <div>
        <h1>Conversaciones</h1>
        <p class="cualify-muted">Historial del bot, de mas antiguo a mas reciente</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-12 col-lg-4 col-xl-3">
        <div class="cualify-card p-0 cualify-scroll">
            <div class="cualify-card-head px-3 py-2">
                <h2 class="mb-0 h6">Telefonos</h2>
            </div>

            <?php if (empty($telefonos)): ?>
                <p class="cualify-muted small p-3 mb-0">Sin conversaciones registradas.</p>
            <?php else: ?>
                <ul class="cualify-conv-list">
                    <?php foreach ($telefonos as $fila): ?>
                        <?php $activo = (string) $fila['phone'] === $telefono; ?>
                        <li>
                            <a href="<?= $e($baseUrl) ?>/conversations?tel=<?= $e(urlencode((string) $fila['phone'])) ?>"
                               class="cualify-conv-item<?= $activo ? ' active' : '' ?>">
                                <div class="cualify-conv-top">
                                    <strong><?= $e($fila['name'] ?: $fila['phone']) ?></strong>
                                    <span class="cualify-muted small"><?= $e((int) $fila['mensajes']) ?> msg</span>
                                </div>
                                <div class="cualify-conv-bottom">
                                    <span class="cualify-muted small"><?= $e($fila['phone']) ?></span>
                                    <span class="cualify-muted small"><?= $e(substr((string) $fila['ultimo_mensaje'], 5, 11)) ?></span>
                                </div>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-12 col-lg-8 col-xl-9">
        <?php if ($telefono === ''): ?>
            <div class="cualify-card">
                <p class="cualify-muted mb-0">Selecciona un telefono para ver su conversacion.</p>
            </div>
        <?php else: ?>
            <div class="cualify-card mb-3">
                <div class="cualify-card-head">
                    <div>
                        <h2 class="mb-1"><?= $e($lead['name'] ?: $telefono) ?></h2>
                        <span class="cualify-muted small"><?= $e($telefono) ?></span>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php if ($lead && !empty($lead['status'])): ?>
                            <span class="cualify-badge cualify-badge-<?= $e($lead['status']) ?>">
                                <?= $e($etiquetasStatus[$lead['status']] ?? $lead['status']) ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($lead && $lead['url_sitio']): ?>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="<?= $e($lead['url_sitio']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                                Ver sitio
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="cualify-card">
                <?php if (empty($conversacion)): ?>
                    <p class="cualify-muted mb-0">Este telefono no tiene historial.</p>
                <?php else: ?>
                    <ul class="cualify-thread cualify-thread-full">
                        <?php foreach ($conversacion as $mensaje): ?>
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
    </div>
</div>
