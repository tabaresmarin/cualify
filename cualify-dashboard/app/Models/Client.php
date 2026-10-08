<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Model;

final class Client extends Model
{
    protected string $table = 'clients';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    /**
     * Lista todos los clientes agregando métricas de productos y cotizaciones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listarClientesConEstadisticas(): array
    {
        return $this->db->fetchAll(
            "SELECT c.id,
                    c.name,
                    c.whatsapp_phone_number_id,
                    c.access_token,
                    (SELECT COUNT(*) FROM products p WHERE p.client_id = c.id) AS total_productos,
                    (SELECT COUNT(*) FROM quotes q WHERE q.client_id = c.id) AS total_cotizaciones
             FROM clients c
             ORDER BY c.id ASC"
        );
    }

    public function crear(string $nombre, string $phoneNumberId, ?string $accessToken = null): int
    {
        $this->db->execute(
            "INSERT INTO clients (name, whatsapp_phone_number_id, access_token) VALUES (?, ?, ?)",
            [trim($nombre), trim($phoneNumberId), $accessToken !== null ? trim($accessToken) : null]
        );

        return $this->db->lastInsertId();
    }

    public function actualizar(int $id, string $nombre, string $phoneNumberId, ?string $accessToken = null): void
    {
        $this->db->execute(
            "UPDATE clients SET name = ?, whatsapp_phone_number_id = ?, access_token = ? WHERE id = ?",
            [trim($nombre), trim($phoneNumberId), $accessToken !== null ? trim($accessToken) : null, $id]
        );
    }
}
