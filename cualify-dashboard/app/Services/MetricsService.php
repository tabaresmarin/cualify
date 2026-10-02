<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Toda la logica de KPIs y agregaciones del panel.
 *
 * Lee las tablas que escribe el bot (leads, jobs_queue, conversation_history,
 * pagespeed_cache) y las resume. No escribe nada.
 *
 * Los valores de `leads.status` son los del enum del schema autoritativo
 * (funciona.sql):
 *   nuevo, en_calificacion, calificado, descartado
 * El handoff a un asesor se registra como 'descartado' porque el enum no tiene
 * un estado propio para eso (ver lead_qualifier.php).
 * Los valores de `clasificacion` salen de clasificarPorRendimiento() en
 * pagespeed_api.php: muy_necesitado, oportunidad_mejora, listo_marketing
 * (mas 'error' y 'sin_sitio' en rutas degradadas).
 */
final class MetricsService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * KPIs principales del dashboard.
     *
     * @return array<string, mixed>
     */
    public function resumen(): array
    {
        return [
            'leads_total'          => $this->conteoLeads(),
            'leads_hoy'            => $this->conteoLeads("DATE(created_at) = CURDATE()"),
            'leads_semana'         => $this->conteoLeads("created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"),
            'leads_mes'            => $this->conteoLeads("created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"),

            // El enum de leads.status no distingue "necesita asesor" de "descartado": el
            // bot mapea el handoff a humano a 'descartado' (lead_qualifier.php).
            // Por eso esta tarjeta cuenta descartados, no un estado aparte.
            'requieren_humano'     => $this->conteoLeads("status = 'descartado'"),
            'en_calificacion'      => $this->conteoLeads("status = 'en_calificacion'"),
            'calificados'          => $this->conteoLeads("status = 'calificado'"),
            'nuevos'               => $this->conteoLeads("status = 'nuevo'"),

            'promedio_pagespeed'   => $this->promedioPageSpeed(),
            'sitios_analizados'    => $this->conteoCache(),

            'mensajes_total'       => (int) $this->db->fetchValueSiExiste('SELECT COUNT(*) FROM conversation_history', [], 0),
            'mensajes_hoy'         => (int) $this->db->fetchValueSiExiste(
                'SELECT COUNT(*) FROM conversation_history WHERE DATE(created_at) = CURDATE()',
                [],
                0
            ),

            'citas_agendadas'      => $this->conteoLeads("cita_fecha IS NOT NULL"),
            'citas_proximas'       => $this->citasProximas(5),

            'cola'                 => $this->colaEstado(),
        ];
    }

    public function conteoLeads(string $donde = '1'): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM leads WHERE {$donde}");
    }

    public function promedioPageSpeed(): ?float
    {
        $valor = $this->db->fetchValueSiExiste(
            'SELECT AVG(pagespeed_score) FROM leads WHERE pagespeed_score IS NOT NULL AND pagespeed_score > 0',
            []
        );

        return $valor === null ? null : round((float) $valor, 1);
    }

    public function conteoCache(): int
    {
        return (int) $this->db->fetchValueSiExiste('SELECT COUNT(*) FROM pagespeed_cache', [], 0);
    }

    /**
     * Serie diaria de leads para el grafico de linea.
     *
     * Rellena los dias sin actividad con cero para que la linea sea continua.
     *
     * @return array<int, array<string, mixed>>
     */
    public function leadsPorDia(int $dias = 30): array
    {
        $dias = max(1, min($dias, 365));

        $filas = $this->db->fetchAll(
            "SELECT DATE(created_at) AS dia, COUNT(*) AS total
             FROM leads
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY dia ASC",
            [$dias]
        );

        $porDia = [];
        foreach ($filas as $fila) {
            $porDia[(string) $fila['dia']] = (int) $fila['total'];
        }

        $serie = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = date('Y-m-d', strtotime("-{$i} days"));
            $serie[] = [
                'fecha' => $fecha,
                'etiqueta' => date('d/m', strtotime($fecha)),
                'total' => $porDia[$fecha] ?? 0,
            ];
        }

        return $serie;
    }

    /**
     * Mensajes por dia, para comparar el volumen de conversacion.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mensajesPorDia(int $dias = 30): array
    {
        $dias = max(1, min($dias, 365));

        $filas = $this->db->fetchAllSiExiste(
            "SELECT DATE(created_at) AS dia, COUNT(*) AS total
             FROM conversation_history
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
             GROUP BY DATE(created_at)
             ORDER BY dia ASC",
            [$dias]
        );

        $porDia = [];
        foreach ($filas as $fila) {
            $porDia[(string) $fila['dia']] = (int) $fila['total'];
        }

        $serie = [];
        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = date('Y-m-d', strtotime("-{$i} days"));
            $serie[] = [
                'fecha' => $fecha,
                'etiqueta' => date('d/m', strtotime($fecha)),
                'total' => $porDia[$fecha] ?? 0,
            ];
        }

        return $serie;
    }

    /** @return array<string, int> */
    public function leadsPorStatus(): array
    {
        $filas = $this->db->fetchAll(
            "SELECT COALESCE(status, 'sin_define') AS status, COUNT(*) AS total
             FROM leads GROUP BY status ORDER BY total DESC"
        );

        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[(string) $fila['status']] = (int) $fila['total'];
        }

        return $resultado;
    }

    /** @return array<string, int> */
    public function leadsPorClasificacion(): array
    {
        $filas = $this->db->fetchAll(
            "SELECT COALESCE(NULLIF(clasificacion, ''), 'sin_clasificar') AS clave, COUNT(*) AS total
             FROM leads GROUP BY clave ORDER BY total DESC"
        );

        $resultado = [];
        foreach ($filas as $fila) {
            $resultado[(string) $fila['clave']] = (int) $fila['total'];
        }

        return $resultado;
    }

    /**
     * Distribucion de puntajes PageSpeed usando los mismos cortes que
     * clasificarPorRendimiento() en el bot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function distribucionPageSpeed(): array
    {
        return [
            [
                'clave'   => 'muy_necesitado',
                'etiqueta' => 'Necesita renovacion (0-50)',
                'total'   => $this->conteoLeads('pagespeed_score BETWEEN 1 AND 50'),
            ],
            [
                'clave'   => 'oportunidad_mejora',
                'etiqueta' => 'Oportunidad de mejora (51-70)',
                'total'   => $this->conteoLeads('pagespeed_score BETWEEN 51 AND 70'),
            ],
            [
                'clave'   => 'listo_marketing',
                'etiqueta' => 'Listo para marketing (71-100)',
                'total'   => $this->conteoLeads('pagespeed_score BETWEEN 71 AND 100'),
            ],
            [
                'clave'   => 'sin_datos',
                'etiqueta' => 'Sin analisis',
                'total'   => $this->conteoLeads('pagespeed_score IS NULL OR pagespeed_score = 0'),
            ],
        ];
    }

    /**
     * Estado de la cola de trabajos del bot.
     *
     * @return array<string, int|float|null>
     */
    public function colaEstado(): array
    {
        $filas = $this->db->fetchAll(
            'SELECT status, COUNT(*) AS total FROM jobs_queue GROUP BY status'
        );

        $porStatus = [];
        foreach ($filas as $fila) {
            $porStatus[(string) $fila['status']] = (int) $fila['total'];
        }

        $promedioSegundos = $this->db->fetchValueSiExiste(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, processed_at))
             FROM jobs_queue
             WHERE processed_at IS NOT NULL AND started_at IS NOT NULL
               AND processed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            []
        );

        return [
            'pending'    => $porStatus['pending'] ?? 0,
            'processing' => $porStatus['processing'] ?? 0,
            'done'       => $porStatus['done'] ?? 0,
            'failed'     => $porStatus['failed'] ?? 0,
            'promedio_segundos' => $promedioSegundos === null ? null : round((float) $promedioSegundos, 1),
        ];
    }

    /**
     * Citas agendadas a partir de hoy.
     *
     * @return array<int, array<string, mixed>>
     */
    public function citasProximas(int $limite = 10): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, phone, email, cita_fecha, cita_hora, pagespeed_score, url_sitio
             FROM leads
             WHERE cita_fecha IS NOT NULL AND cita_fecha >= CURDATE()
             ORDER BY cita_fecha ASC, cita_hora ASC
             LIMIT ' . (int) $limite
        );
    }

    /**
     * Jobs atascados: processing hace mas de 10 minutos. Un job en ese
     * estado casi siempre es un worker que murio a mitad de trabajo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function jobsAtascados(int $minutos = 10): array
    {
        return $this->db->fetchAll(
            "SELECT id, phone, job_type, attempts, started_at,
                    TIMESTAMPDIFF(MINUTE, started_at, NOW()) AS minutos
             FROM jobs_queue
             WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
             ORDER BY started_at ASC
             LIMIT 50",
            [$minutos]
        );
    }

    /**
     * Payload completo que consume el endpoint /api/metrics.
     *
     * @return array<string, mixed>
     */
    public function metricasCompletas(int $dias = 30): array
    {
        return [
            'generado_en'  => date('c'),
            'dias'         => $dias,
            'resumen'      => $this->resumen(),
            'leads_por_dia'      => $this->leadsPorDia($dias),
            'mensajes_por_dia'   => $this->mensajesPorDia($dias),
            'por_status'         => $this->leadsPorStatus(),
            'por_clasificacion'  => $this->leadsPorClasificacion(),
            'distribucion_psi'   => $this->distribucionPageSpeed(),
        ];
    }

    /**
     * Etiquetas legibles para los estados del bot.
     *
     * La clave es el enum real de `leads.status` en el schema autoritativo.
     * Se incluyen tambien los valores del bootstrap antiguo de database.sql
     * ('new', 'qualified', 'disqualified', 'scheduled') por si alguna
     * instalacion vieja todavia los tiene en la base: sin esta fila, un lead
     * asi aparecia con la etiqueta cruda y desaparecia del filtro.
     *
     * @return array<string, string>
     */
    public static function etiquetasStatus(): array
    {
        return [
            'nuevo'           => 'Nuevo',
            'new'             => 'Nuevo',
            'en_calificacion' => 'En calificacion',
            'calificado'      => 'Calificado',
            'qualified'       => 'Calificado',
            'descartado'      => 'Descartado / requiere asesor',
            'disqualified'    => 'Descartado',
            'scheduled'       => 'Cita agendada',
            'sin_define'      => 'Sin estado',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function etiquetasClasificacion(): array
    {
        return [
            'muy_necesitado'     => 'Necesita renovacion',
            'oportunidad_mejora' => 'Oportunidad de mejora',
            'listo_marketing'    => 'Listo para marketing',
            'sin_sitio'          => 'Sin sitio web',
            'sin_clasificar'     => 'Sin clasificar',
            'error'              => 'Error de analisis',
        ];
    }
}
