<?php
/**
 * @var array<int, array<string, mixed>> $clientes
 * @var string $baseUrl
 * @var callable $e
 */
?>

<div class="cualify-page-head">
    <div>
        <h1>Clientes Multi-Tenancy</h1>
        <p class="cualify-muted">Gestión de cuentas y credenciales de WhatsApp Business</p>
    </div>
    <div>
        <a href="<?= $e($baseUrl) ?>/clients/crear" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Nuevo Cliente
        </a>
    </div>
</div>

<div class="cualify-card">
    <div class="cualify-card-head">
        <h2>Clientes Registrados</h2>
        <span class="cualify-muted small"><?= $e(count($clientes)) ?> cuenta(s)</span>
    </div>

    <?php if (empty($clientes)): ?>
        <p class="cualify-muted mb-0">No hay clientes registrados aún.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table cualify-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre / Empresa</th>
                        <th>WhatsApp Phone Number ID</th>
                        <th>Token Configurado</th>
                        <th>Catálogo</th>
                        <th>Cotizaciones</th>
                        <th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($clientes as $c): ?>
                    <tr>
                        <td><code>#<?= $e($c['id']) ?></code></td>
                        <td><strong><?= $e($c['name']) ?></strong></td>
                        <td><code><?= $e($c['whatsapp_phone_number_id'] ?? 's/d') ?></code></td>
                        <td>
                            <?php if (!empty($c['access_token'])): ?>
                                <span class="badge bg-success">Configurado</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Usa .env global</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-info text-dark"><?= $e($c['total_productos'] ?? 0) ?> productos</span></td>
                        <td><span class="badge bg-light text-dark"><?= $e($c['total_cotizaciones'] ?? 0) ?> cotizaciones</span></td>
                        <td>
                            <a href="<?= $e($baseUrl) ?>/clients/<?= $e($c['id']) ?>/editar" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Editar
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
