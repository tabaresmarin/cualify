<?php
/**
 * @var string $baseUrl
 * @var string $titulo
 * @var array<string, mixed>|null $usuarioActual
 * @var callable $e
 */

$secciones = [
    ['/dashboard',     'Dashboard',       'bi-speedometer2'],
    ['/leads',         'Leads',           'bi-people'],
    ['/conversations', 'Conversaciones',  'bi-chat-dots'],
    ['/outreach',      'Iniciar Chat',    'bi-send-fill'],
    ['/pagespeed',     'Rendimiento',     'bi-speedometer'],
    ['/clients',       'Clientes',        'bi-building'],
];

$actual = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
?>
<aside class="cualify-sidebar">
    <div class="cualify-sidebar-brand">
        <span class="cualify-logo">EISO</span>
        <div>
            <strong>Cualify</strong>
            <small>Panel comercial</small>
        </div>
    </div>

    <nav class="cualify-nav">
        <?php foreach ($secciones as [$ruta, $texto, $icono]): ?>
            <?php $activo = str_contains($actual, rtrim($baseUrl, '/') . $ruta) || ($ruta === '/dashboard' && $actual === rtrim($baseUrl, '/')); ?>
            <a href="<?= $e($baseUrl) ?><?= $ruta ?>"
               class="cualify-nav-link<?= $activo ? ' active' : '' ?>">
                <i class="bi <?= $e($icono) ?>"></i>
                <span><?= $e($texto) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="cualify-sidebar-foot">
        <small>Bot de calificacion</small>
        <small>v1.0</small>
    </div>
</aside>
