<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

/**
 * Lectura de la tabla `leads`, que es propiedad del bot (webhook.php /
 * worker_*.php). El panel solo la consulta: nunca escribe en ella.
 */
final class Lead extends Model
{
    protected string $table = 'leads';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * Listado con busqueda y filtros para la pantalla de leads.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listar(array $filtros, int $limite = 500): array
    {
        $sql = 'SELECT id, name, phone, email, status, step, url_sitio, pagespeed_score,
                       clasificacion, servicio_interes, cita_fecha, cita_hora, created_at
                FROM leads';
        $params = [];

        // La busqueda libre no cabe en construirWhere(): necesita LIKE sobre varias columnas.
        $busqueda = trim((string) ($filtros['q'] ?? ''));
        unset($filtros['q']);

        $condiciones = $this->construirWhere($filtros, $params);
        $where = $condiciones[0];
        $params = $condiciones[1];

        if ($busqueda !== '') {
            $like = '%' . $busqueda . '%';
            $clausula = '(name LIKE ? OR phone LIKE ? OR email LIKE ? OR url_sitio LIKE ? OR servicio_interes LIKE ?)';
            $params = array_merge($params, [$like, $like, $like, $like, $like]);

            $where = $where === ''
                ? ' WHERE ' . $clausula
                : $where . ' AND ' . $clausula;
        }

        $sql .= $where . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limite;

        return $this->db->fetchAll($sql, $params);
    }

    /** @return array<string, mixed>|null */
    public function porTelefono(string $telefono): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, name, phone, email, status, step, url_sitio, pagespeed_score,
                    clasificacion, servicio_interes, cita_fecha, cita_hora, created_at
             FROM leads WHERE phone = ? ORDER BY id DESC LIMIT 1',
            [$telefono]
        );
    }

    /** @return array<string, mixed>|null */
    public function detalle(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM leads WHERE id = ? LIMIT 1', [$id]);
    }

    /**
     * Historial conversacional de un telefono, del mas nuevo al mas viejo.
     *
     * @return array<int, array<string, mixed>>
     */
    public function conversacion(string $telefono, int $limite = 200): array
    {
        // conversation_history no existe en algunas instalaciones: se degrada a
        // historial vacío en vez de romper la página.
        $filas = $this->db->fetchAllSiExiste(
            'SELECT role, content, created_at FROM conversation_history
             WHERE phone = ? ORDER BY id DESC LIMIT ' . (int) $limite,
            [$telefono]
        );

        return array_reverse($filas);
    }

    /**
     * Telefonos con actividad conversacional, ordenados por el ultimo mensaje.
     *
     * @param int    $offset  Saltar ya N hilos (paginacion). El ORDER BY es
     *                        estable, asi que el corte no cambia entre llamadas.
     * @param string $busqueda Filtra por telefono o por nombre del lead (LIKE).
     * @return array<int, array<string, mixed>>
     */
    public function telefonosConversando(int $limite = 100, string $busqueda = '', int $offset = 0): array
    {
        $limite = max(1, min($limite, 200));
        $offset = max(0, $offset);
        $busqueda = trim($busqueda);

        if ($busqueda !== '') {
            return $this->db->fetchAllSiExiste(
                "SELECT ch.phone,
                        MAX(ch.created_at) AS ultimo_mensaje,
                        COUNT(*)          AS mensajes,
                        l.name,
                        l.url_sitio,
                        l.status,
                        l.pagespeed_score
                 FROM conversation_history ch
                 LEFT JOIN leads l ON l.phone = ch.phone
                 WHERE ch.phone LIKE ? OR l.name LIKE ?
                 GROUP BY ch.phone, l.name, l.url_sitio, l.status, l.pagespeed_score
                 ORDER BY ultimo_mensaje DESC, ch.phone ASC
                 LIMIT {$limite} OFFSET {$offset}",
                ['%' . $busqueda . '%', '%' . $busqueda . '%']
            );
        }

        return $this->db->fetchAllSiExiste(
            "SELECT ch.phone,
                    MAX(ch.created_at) AS ultimo_mensaje,
                    COUNT(*)          AS mensajes,
                    l.name,
                    l.url_sitio,
                    l.status,
                    l.pagespeed_score
             FROM conversation_history ch
             LEFT JOIN leads l ON l.phone = ch.phone
             GROUP BY ch.phone, l.name, l.url_sitio, l.status, l.pagespeed_score
             ORDER BY ultimo_mensaje DESC, ch.phone ASC
             LIMIT {$limite} OFFSET {$offset}"
        );
    }

    /**
     * Devuelve la cantidad total de hilos (telefonos distintos) con historial.
     */
    public function conteoTelefonosConversando(string $busqueda = ''): int
    {
        $busqueda = trim($busqueda);

        if ($busqueda !== '') {
            return (int) $this->db->fetchValueSiExiste(
                "SELECT COUNT(DISTINCT ch.phone)
                 FROM conversation_history ch
                 LEFT JOIN leads l ON l.phone = ch.phone
                 WHERE ch.phone LIKE ? OR l.name LIKE ?",
                ['%' . $busqueda . '%', '%' . $busqueda . '%'],
                0
            );
        }

        return (int) $this->db->fetchValueSiExiste(
            "SELECT COUNT(DISTINCT ch.phone) FROM conversation_history ch",
            [],
            0
        );
    }

    /**
     * Hilos completos de varios telefonos en UNA sola consulta.
     *
     * La vista muestra todas las conversaciones en la misma pagina, asi que
     * pedir `conversacion()` en bucle por telefono seria un N+1: por cada
     * pantalla se irian hasta HILOS_POR_PAGINA consultas a
     * conversation_history.
     *
     * Si un hilo supera $limitePorHilo se conservan los mensajes MAS RECIENTES
     * y se descartan los antiguos. El total real del hilo se devuelve aparte
     * para poder avisar "mostrando los ultimos N de M".
     *
     * @param  array<int, string> $telefonos
     * @return array<string, array{mensajes: array<int, array<string, mixed>>, total: int}>
     */
    public function conversacionesDe(array $telefonos, int $limitePorHilo = 200): array
    {
        $telefonos = array_values(array_unique(array_filter(array_map('strval', $telefonos), static fn ($t): bool => $t !== '')));

        if ($telefonos === []) {
            return [];
        }

        $marcadores = implode(',', array_fill(0, count($telefonos), '?'));

        $filas = $this->db->fetchAllSiExiste(
            "SELECT id, phone, role, content, created_at
             FROM conversation_history
             WHERE phone IN ({$marcadores})
             ORDER BY id ASC",
            $telefonos
        );

        $porTelefono = [];
        foreach ($telefonos as $telefono) {
            $porTelefono[$telefono] = [];
        }

        foreach ($filas as $fila) {
            $telefono = (string) $fila['phone'];

            if (!isset($porTelefono[$telefono])) {
                continue;
            }

            unset($fila['id']);
            $porTelefono[$telefono][] = $fila;
        }

        foreach ($porTelefono as $telefono => $mensajes) {
            $porTelefono[$telefono] = [
                'mensajes' => array_slice($mensajes, -max(1, $limitePorHilo)),
                'total'    => count($mensajes),
            ];
        }

        return $porTelefono;
    }

    /** @return array<int, string> */
    public function telefonosConHistorial(): array
    {
        $filas = $this->db->fetchAllSiExiste('SELECT DISTINCT phone FROM conversation_history ORDER BY phone');

        return array_map(static fn (array $f): string => (string) $f['phone'], $filas);
    }

    /**
     * Analisis de PageSpeed guardados en la cache del bot.
     *
     * @return array<int, array<string, mixed>>
     */
    public function analisisPagespeed(int $limite = 500): array
    {
        return $this->db->fetchAllSiExiste(
            'SELECT url, score, strategy, created_at, expires_at
             FROM pagespeed_cache
             ORDER BY created_at DESC
             LIMIT ' . (int) $limite
        );
    }
}
