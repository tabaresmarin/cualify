<?php
/**
 * @var string $baseUrl
 * @var array<string, mixed>|null $usuarioActual
 * @var callable $e
 */
?>
<header class="cualify-header">
    <div class="cualify-header-info">
        <span class="cualify-header-dot"></span>
        <span>Sistema en linea</span>
    </div>

    <div class="cualify-header-user">
        <?php if (!empty($usuarioActual)): ?>
            <div class="cualify-user-meta">
                <strong><?= $e($usuarioActual['name']) ?></strong>
                <small><?= $e($usuarioActual['email']) ?></small>
            </div>
            <form method="post" action="<?= $e($baseUrl) ?>/logout" class="cualify-logout">
                <input type="hidden" name="_token" value="<?= $e($csrf ?? '') ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Cerrar sesion">
                    <i class="bi bi-box-arrow-right"></i>
                </button>
            </form>
        <?php endif; ?>
    </div>
</header>
