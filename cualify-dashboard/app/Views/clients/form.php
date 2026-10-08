<?php
/**
 * @var array<string, mixed>|null $cliente
 * @var string $baseUrl
 * @var callable $e
 */

$esEdicion = $cliente !== null;
$actionUrl = $esEdicion
    ? $baseUrl . '/clients/' . $e($cliente['id']) . '/editar'
    : $baseUrl . '/clients/crear';
?>

<div class="cualify-page-head">
    <div>
        <h1><?= $esEdicion ? 'Editar Cliente' : 'Nuevo Cliente' ?></h1>
        <p class="cualify-muted">Configura las credenciales de la API de WhatsApp Cloud para este negocio</p>
    </div>
    <div>
        <a href="<?= $e($baseUrl) ?>/clients" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Volver a Clientes
        </a>
    </div>
</div>

<div class="row justify-content::center">
    <div class="col-12 col-lg-8">
        <div class="cualify-card">
            <form action="<?= $actionUrl ?>" method="POST">
                <div class="mb-3">
                    <label for="name" class="form-label">Nombre del Cliente / Empresa <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name"
                           value="<?= $e($cliente['name'] ?? '') ?>" required placeholder="Ej: EISO Agencia / Clínica Dental Alpha">
                </div>

                <div class="mb-3">
                    <label for="whatsapp_phone_number_id" class="form-label">WhatsApp Phone Number ID <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="whatsapp_phone_number_id" name="whatsapp_phone_number_id"
                           value="<?= $e($cliente['whatsapp_phone_number_id'] ?? '') ?>" required placeholder="Ej: 104829102938102">
                    <div class="form-text">ID numérico asignado por Meta en el portal de desarrolladores para esta cuenta de WhatsApp.</div>
                </div>

                <div class="mb-4">
                    <label for="access_token" class="form-label">WhatsApp Access Token (Opcional)</label>
                    <textarea class="form-control font-monospace" id="access_token" name="access_token" rows="3"
                              placeholder="EAA... (si se deja en blanco usará el WA_ACCESS_TOKEN global del archivo .env)"><?= $e($cliente['access_token'] ?? '') ?></textarea>
                    <div class="form-text">Token de acceso permanente de la App de Meta.</div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a href="<?= $e($baseUrl) ?>/clients" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> <?= $esEdicion ? 'Guardar Cambios' : 'Registrar Cliente' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
