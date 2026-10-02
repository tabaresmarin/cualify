<?php
/**
 * @var string|null $error
 * @var string $email
 * @var string $next
 *
 * Todos llegan con fallback: mostrarLogin() no siempre pasa error/email.
 */
$error = $error ?? null;
$email = $email ?? '';
$next  = $next ?? '';
?>
<?php if ($error !== null && $error !== ''): ?>
    <div class="alert alert-danger"><?= $e($error) ?></div>
<?php endif; ?>

<form method="post" action="<?= $e($baseUrl) ?>/login" autocomplete="off">
    <input type="hidden" name="_token" value="<?= $e($csrf ?? '') ?>">
    <input type="hidden" name="next" value="<?= $e($next) ?>">

    <div class="mb-3">
        <label class="form-label" for="email">Correo</label>
        <input type="email" class="form-control" id="email" name="email"
               value="<?= $e($email) ?>" required autofocus autocomplete="username">
    </div>

    <div class="mb-4">
        <label class="form-label" for="password">Contrasena</label>
        <input type="password" class="form-control" id="password" name="password"
               required autocomplete="current-password">
    </div>

    <button type="submit" class="btn btn-primary w-100">Entrar</button>
</form>
