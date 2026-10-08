<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\Lead;

final class OutreachController extends Controller
{
    public function index(Request $request, Response $response, array $params): never
    {
        $this->render('outreach.index', [
            'titulo'    => 'Iniciar Conversación (Outreach)',
            'resultados' => null,
            'telefonos' => '',
            'nombre'    => '',
            'tipo'      => 'flow_consultar_servicios',
        ]);
    }

    public function enviar(Request $request, Response $response, array $params): never
    {
        /** @var Session $session */
        $session = $this->app->make(Session::class);

        $telefonosRaw = (string) $request->input('telefonos', '');
        $nombre       = trim((string) $request->input('nombre', ''));
        $tipo         = trim((string) $request->input('tipo', 'flow_consultar_servicios'));

        // Cargar utilidades y APIs del bot raíz
        $rootPath = dirname(__DIR__, 3);
        require_once $rootPath . '/config.php';
        require_once $rootPath . '/database.php';
        require_once $rootPath . '/whatsapp_api.php';
        require_once $rootPath . '/lead_qualifier.php';

        $pdo = obtenerConexion();

        // Extraer números de teléfono (separados por líneas, comas, espacios)
        $lineas = preg_split('/[\r\n,;\s]+/', $telefonosRaw);
        $numeros = [];
        foreach ($lineas as $l) {
            $num = preg_replace('/\D/', '', $l);
            if (strlen($num) >= 10) {
                $numeros[] = $num;
            }
        }

        $numeros = array_unique($numeros);

        if (empty($numeros)) {
            $session->flash('error', 'Por favor ingresa al menos un número de teléfono válido (mínimo 10 dígitos).');
            $this->render('outreach.index', [
                'titulo'     => 'Iniciar Conversación (Outreach)',
                'resultados' => null,
                'telefonos'  => $telefonosRaw,
                'nombre'     => $nombre,
                'tipo'       => $tipo,
            ]);
        }

        $resultados = [];

        foreach ($numeros as $phone) {
            // 1. Registrar/Actualizar lead en la BD
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO leads (phone, name, status, created_at)
                     VALUES (?, ?, 'nuevo', NOW())
                     ON DUPLICATE KEY UPDATE
                        name = IF(VALUES(name) != '', VALUES(name), name),
                        status = IF(status = 'descartado', 'nuevo', status)"
                );
                $stmt->execute([$phone, $nombre !== '' ? $nombre : null]);
            } catch (\Throwable $e) {
                error_log("[OutreachController] Error guardando lead $phone: " . $e->getMessage());
            }

            // 2. Disparar según el tipo seleccionado
            if ($tipo === 'flow_consultar_servicios') {
                // Mismo flujo que test_send.php
                try {
                    $respuestaRaw = enviarPlantillaWhatsApp($phone, "consultar_servicios", "en", [], [
                        [
                            'sub_type'   => 'flow',
                            'index'      => 0,
                            'flow_token' => $phone,
                        ]
                    ]);

                    $res = json_decode((string) $respuestaRaw, true);
                    if (isset($res['messages'][0]['id'])) {
                        $resultados[] = [
                            'phone'    => $phone,
                            'exito'    => true,
                            'meta_id'  => $res['messages'][0]['id'],
                            'mensaje'  => 'Plantilla Flow "consultar_servicios" enviada correctamente.',
                        ];
                    } else {
                        $resultados[] = [
                            'phone'   => $phone,
                            'exito'   => false,
                            'meta_id' => null,
                            'mensaje' => 'Meta no aceptó la plantilla: ' . json_encode($res, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                } catch (\Throwable $e) {
                    $resultados[] = [
                        'phone'   => $phone,
                        'exito'   => false,
                        'meta_id' => null,
                        'mensaje' => 'Excepción enviando plantilla: ' . $e->getMessage(),
                    ];
                }
            } elseif ($tipo === 'plantilla_bienvenida') {
                try {
                    $templateDefault = valorEntorno('INBOUND_DEFAULT_TEMPLATE') ?: 'cualify_lead_welcome';
                    $respuestaRaw = enviarPlantillaWhatsApp($phone, $templateDefault, "es", [$nombre !== '' ? $nombre : 'Hola']);
                    $res = json_decode((string) $respuestaRaw, true);

                    if (isset($res['messages'][0]['id'])) {
                        $resultados[] = [
                            'phone'   => $phone,
                            'exito'   => true,
                            'meta_id' => $res['messages'][0]['id'],
                            'mensaje' => "Plantilla HSM '{$templateDefault}' enviada correctamente.",
                        ];
                    } else {
                        $resultados[] = [
                            'phone'   => $phone,
                            'exito'   => false,
                            'meta_id' => null,
                            'mensaje' => 'Error de Meta: ' . json_encode($res, JSON_UNESCAPED_UNICODE),
                        ];
                    }
                } catch (\Throwable $e) {
                    $resultados[] = [
                        'phone'   => $phone,
                        'exito'   => false,
                        'meta_id' => null,
                        'mensaje' => 'Excepción: ' . $e->getMessage(),
                    ];
                }
            } else { // 'atencion_ia_inmediata'
                try {
                    $saludo = "Hola, vi que solicitaste información sobre los servicios de EISO. ¿En qué podemos ayudarte?";
                    $jobId = encolarMensajeIA($pdo, $phone, $saludo);
                    if ($jobId) {
                        dispararWorker('ia');
                        $resultados[] = [
                            'phone'   => $phone,
                            'exito'   => true,
                            'meta_id' => "Job #{$jobId}",
                            'mensaje' => 'Atención con IA encolada y worker disparado.',
                        ];
                    } else {
                        $resultados[] = [
                            'phone'   => $phone,
                            'exito'   => false,
                            'meta_id' => null,
                            'mensaje' => 'No se pudo encolar el trabajo en la base de datos.',
                        ];
                    }
                } catch (\Throwable $e) {
                    $resultados[] = [
                        'phone'   => $phone,
                        'exito'   => false,
                        'meta_id' => null,
                        'mensaje' => 'Excepción: ' . $e->getMessage(),
                    ];
                }
            }
        }

        $session->flash('exito', 'Proceso de inicio de conversaciones completado para ' . count($numeros) . ' número(s).');

        $this->render('outreach.index', [
            'titulo'     => 'Iniciar Conversación (Outreach)',
            'resultados' => $resultados,
            'telefonos'  => $telefonosRaw,
            'nombre'     => $nombre,
            'tipo'       => $tipo,
        ]);
    }
}
