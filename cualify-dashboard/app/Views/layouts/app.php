<?php
/**
 * @var string $titulo
 * @var string $contenido
 * @var string $baseUrl
 * @var string $vista
 * @var callable $e
 * @var array<int, array{tipo: string, mensaje: string}>|null $flash
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($titulo ?? 'Panel') ?> &middot; Cualify</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/app.css">
</head>
<body class="cualify-body">

<div class="cualify-shell">
    <?= $this->parcial('sidebar', ['titulo' => $titulo ?? '', 'baseUrl' => $baseUrl, 'e' => $e]) ?>

    <div class="cualify-main">
        <?= $this->parcial('header', ['baseUrl' => $baseUrl, 'e' => $e]) ?>

        <main class="cualify-content">
            <?php if (!empty($flash)): ?>
                <?php foreach ($flash as $mensaje): ?>
                    <?php
                    $clase = match ($mensaje['tipo']) {
                        'error'  => 'alert-danger',
                        'aviso'  => 'alert-warning',
                        'exito'  => 'alert-success',
                        default  => 'alert-info',
                    };
                    ?>
                    <div class="alert <?= $e($clase) ?> alert-dismissible fade show" role="alert">
                        <?= $e($mensaje['mensaje']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?= $contenido ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= $e($baseUrl) ?>/assets/js/app.js"></script>
</body>
</html>
