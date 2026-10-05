<?php
/**
 * Todas las conversaciones del bot en una sola pagina, un hilo por telefono y
 * del mas antiguo al mas reciente dentro de cada hilo.
 *
 * @var array<int, array<string, mixed>> $telefonos
 * @var array<string, array{mensajes: array<int, array<string, mixed>>, total: int}> $hilos
 * @var string $busqueda
 * @var int $pagina
 * @var int $totalPaginas
 * @var int $totalHilos
 * @var string $foco
 * @var string $baseUrl
 * @var callable $e
 */

use App\Services\MetricsService;

$etiquetasStatus = MetricsService::etiquetasStatus();
?>

<div class="cualify-page-head">
    <div>
        <h1>Conversaciones</h1>
        <p class="cualify-muted">
            <?= $e($totalHilos) ?> conversacion(es) con historial, del hilo mas antiguo al mas reciente
        </p>
    </div>
</div>

<form method="get" action="<?= $e($baseUrl) ?>/conversations" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-5">
        <label class="form-label small" for="q">Buscar</label>
        <input type="search" class="form-control" id="q" name="q"
               value="<?= $e($busqueda) ?>"
               placeholder="Telefono o nombre del lead">
    </div>
    <div class="col-12 col-md-auto">
        <button type="submit" class="btn btn-outline-secondary">Buscar</button>
        <?php if ($busqueda !== ''): ?>
            <a href="<?= $e($baseUrl) ?>/conversations" class="btn btn-outline-secondary">Limpiar</a>
        <?php endif; ?>
    </div>
</form>

<?php if (empty($telefonos)): ?>
    <div class="cualify-card">
        <p class="cualify-muted mb-0">
            <?= $busqueda !== '' ? 'Ningun hilo coincide con la busqueda.' : 'Sin conversaciones registradas.' ?>
        </p>
    </div>
<?php else: ?>

    <?php foreach ($telefonos as $fila): ?>
        <?php
        $telefono = (string) $fila['phone'];
        $hilo     = $hilos[$telefono] ?? ['mensajes' => [], 'total' => 0];
        $mensajes = $hilo['mensajes'];
        $total    = (int) $hilo['total'];
        $recortado = $total > count($mensajes);
        ?>
        <section class="cualify-card mb-3 cualify-hilo<?= $telefono === $foco ? ' cualify-hilo-foco' : '' ?>"
                 id="tel-<?= $e($telefono) ?>">
            <div class="cualify-card-head">
                <div>
                    <h2 class="mb-1">
                        <?= $e($fila['name'] ?: $telefono) ?>
                        <span class="cualify-muted small fw-normal"><?= $e($telefono) ?></span>
                    </h2>
                    <span class="cualify-muted small">
                        <?= $e($total) ?> mensaje(s)
                        &middot; ultimo <?= $e(substr((string) $fila['ultimo_mensaje'], 5, 11)) ?>
                    </span>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if (!empty($fila['status'])): ?>
                        <span class="cualify-badge cualify-badge-<?= $e($fila['status']) ?>">
                            <?= $e($etiquetasStatus[$fila['status']] ?? $fila['status']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if (!empty($fila['url_sitio'])): ?>
                        <a class="btn btn-sm btn-outline-secondary"
                           href="<?= $e($fila['url_sitio']) ?>" target="_blank" rel="noopener noreferrer nofollow">
                            Ver sitio
                        </a>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-outline-secondary"
                       href="<?= $e($baseUrl) ?>/leads?q=<?= $e(urlencode($telefono)) ?>">Ver lead</a>
                </div>
            </div>

            <?php if ($recortado): ?>
                <p class="cualify-muted small mb-2">
                    Mostrando los <?= $e(count($mensajes)) ?> mensajes mas recientes de <?= $e($total) ?>.
                </p>
            <?php endif; ?>

            <?php if (empty($mensajes)): ?>
                <p class="cualify-muted mb-0">Este telefono no tiene historial.</p>
            <?php else: ?>
                <ul class="cualify-thread cualify-thread-full">
                    <?php foreach ($mensajes as $mensaje): ?>
                        <li class="cualify-msg cualify-msg-<?= $e($mensaje['role']) ?>">
                            <span class="cualify-msg-role"><?= $e($mensaje['role']) ?></span>
                            <span class="cualify-msg-body"><?= nl2br($e($mensaje['content'])) ?></span>
                            <span class="cualify-msg-time"><?= $e(substr((string) $mensaje['created_at'], 5, 11)) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

    <?php if ($totalPaginas > 1): ?>
        <nav class="cualify-paginacion" aria-label="Paginas de conversaciones">
            <?php
            $query = static function (int $p) use ($busqueda, $baseUrl): string {
                $params = ['pagina' => $p];
                if ($busqueda !== '') {
                    $params['q'] = $busqueda;
                }

                return $baseUrl . '/conversations?' . http_build_query($params);
            };
            ?>
            <a class="btn btn-sm btn-outline-secondary<?= $pagina <= 1 ? ' disabled' : '' ?>"
               href="<?= $e($query(max(1, $pagina - 1))) ?>">Anterior</a>
            <span class="cualify-muted small">Pagina <?= $e($pagina) ?> de <?= $e($totalPaginas) ?></span>
            <a class="btn btn-sm btn-outline-secondary<?= $pagina >= $totalPaginas ? ' disabled' : '' ?>"
               href="<?= $e($query(min($totalPaginas, $pagina + 1))) ?>">Siguiente</a>
        </nav>
    <?php endif; ?>

    <script>
        window.CUALIFY_FOCO = <?= json_encode($foco) ?>;
    </script>
<?php endif; ?>