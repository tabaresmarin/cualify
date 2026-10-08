<?php
/**
 * @var array<int, array<string, mixed>>|null $resultados
 * @var string $telefonos
 * @var string $nombre
 * @var string $tipo
 * @var string $baseUrl
 * @var callable $e
 */
?>

<div class="cualify-page-head">
    <div>
        <h1>Iniciar Conversación (Outreach)</h1>
        <p class="cualify-muted">Envía mensajes iniciales proactivos por WhatsApp para activar leads e iniciar el flujo de calificación</p>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12 col-lg-7">
        <div class="cualify-card">
            <div class="cualify-card-head">
                <h2>Formulario de Disparo Inicial</h2>
            </div>

            <form action="<?= $e($baseUrl) ?>/outreach/enviar" method="POST">
                <div class="mb-3">
                    <label for="telefonos" class="form-label">Número(s) de Celular / WhatsApp <span class="text-danger">*</span></label>
                    <textarea class="form-control font-monospace" id="telefonos" name="telefonos" rows="4" required
                              placeholder="Ej: 573045235358&#10;573001234567&#10;(Puedes ingresar varios separados por coma o salto de línea)"><?= $e($telefonos) ?></textarea>
                    <div class="form-text">Ingresa el número con código de país sin el símbolo + (Ej: 573045235358 para Colombia).</div>
                </div>

                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre del Lead (Opcional)</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" value="<?= $e($nombre) ?>"
                           placeholder="Ej: Carlos Mendoza">
                </div>

                <div class="mb-4">
                    <label for="tipo" class="form-label">Método de Inicio de Conversación <span class="text-danger">*</span></label>
                    <select class="form-select" id="tipo" name="tipo" required>
                        <option value="flow_consultar_servicios" <?= $tipo === 'flow_consultar_servicios' ? 'selected' : '' ?>>
                            ⭐ Plantilla Flow "consultar_servicios" (Mismo de test_send.php)
                        </option>
                        <option value="plantilla_bienvenida" <?= $tipo === 'plantilla_bienvenida' ? 'selected' : '' ?>>
                            ✉️ Plantilla HSM Estándar de Bienvenida (INBOUND_DEFAULT_TEMPLATE)
                        </option>
                        <option value="atencion_ia_inmediata" <?= $tipo === 'atencion_ia_inmediata' ? 'selected' : '' ?>>
                            🤖 Encolar Atencion proactiva con IA (Worker Gemini)
                        </option>
                    </select>
                </div>

                <div class="d-flex justify-content-end">
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-send-fill me-1"></i> Enviar Mensajes Iniciales
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-12 col-lg-5">
        <div class="cualify-card bg-light border-0">
            <div class="cualify-card-head">
                <h2 class="h6"><i class="bi bi-info-circle text-primary me-1"></i> Ventana de 24 horas de Meta</h2>
            </div>
            <p class="small text-muted mb-2">
                WhatsApp Business prohíbe enviar mensajes de texto libre a números que no han escrito en las últimas 24 horas.
            </p>
            <p class="small text-muted mb-2">
                Por esa razón, para <strong>iniciar conversación</strong> con un cliente que aún no ha escrito, la API requiere enviar una <strong>Plantilla aprobada (HSM)</strong> como <code>consultar_servicios</code>.
            </p>
            <p class="small text-muted mb-0">
                Una vez que el usuario hace clic en el botón del Flow o responde a la plantilla, la ventana de 24h se abre y el bot interactúa libremente.
            </p>
        </div>
    </div>
</div>

<?php if ($resultados !== null): ?>
    <div class="cualify-card mt-4">
        <div class="cualify-card-head">
            <h2>Resultados del Envió Outbound</h2>
            <span class="cualify-muted small"><?= count($resultados) ?> destino(s) procesado(s)</span>
        </div>

        <div class="table-responsive">
            <table class="table cualify-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Teléfono Destino</th>
                        <th>Estado</th>
                        <th>ID Meta / Job</th>
                        <th>Detalle de Respuesta</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td><code>+<?= $e($r['phone']) ?></code></td>
                        <td>
                            <?php if ($r['exito']): ?>
                                <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> Enviado</span>
                            <?php else: ?>
                                <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> Error</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= $e($r['meta_id'] ?? 'N/A') ?></code></td>
                        <td class="small text-muted"><?= $e($r['mensaje']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
