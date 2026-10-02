<?php
/**
 * @var string $titulo
 * @var string $contenido
 * @var string $baseUrl
 * @var string $error|null
 * @var string $email
 * @var string $next
 * @var callable $e
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($titulo ?? 'Acceso') ?> &middot; Cualify</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $e($baseUrl) ?>/assets/css/app.css">
</head>
<body class="cualify-auth-body">

<div class="cualify-auth-card">
    <div class="cualify-auth-brand">
        <span class="cualify-auth-logo">EISO</span>
        <h1>Panel Cualify</h1>
        <p>Acceso interno para el equipo comercial</p>
    </div>

    <div class="cualify-auth-form">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($flash)): ?>
            <?php foreach ($flash as $mensaje): ?>
                <div class="alert alert-<?= $e($mensaje['tipo'] === 'error' ? 'danger' : 'info') ?>">
                    <?= $e($mensaje['mensaje']) ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <form method="post" action="<?= $e($baseUrl) ?>/login" autocomplete="off">
            <input type="hidden" name="_token" value="<?= $e($csrf ?? '') ?>">
            <input type="hidden" name="next" value="<?= $e($next ?? '') ?>">

            <div class="mb-3">
                <label class="form-label" for="email">Correo</label>
                <input type="email" class="form-control" id="email" name="email"
                       value="<?= $e($email ?? '') ?>" required autofocus
                       autocomplete="username">
            </div>

            <div class="mb-4">
                <label class="form-label" for="password">Contrasena</label>
                <input type="password" class="form-control" id="password" name="password"
                       required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary w-100">Entrar</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
