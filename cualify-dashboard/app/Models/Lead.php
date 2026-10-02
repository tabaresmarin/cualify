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
     * Telefonos con actividad conversacional, para el listado lateral.
     *
     * @return array<int, array<string, mixed>>
     */
    public function telefonosConversando(int $limite = 100): array
    {
        return $this->db->fetchAllSiExiste(
            'SELECT ch.phone,
                    MAX(ch.created_at) AS ultimo_mensaje,
                    COUNT(*)          AS mensajes,
                    l.name,
                    l.status,
                    l.pagespeed_score
             FROM conversation_history ch
             LEFT JOIN leads l ON l.phone = ch.phone
             GROUP BY ch.phone, l.name, l.status, l.pagespeed_score
             ORDER BY ultimo_mensaje DESC
             LIMIT ' . (int) $limite
        );
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
