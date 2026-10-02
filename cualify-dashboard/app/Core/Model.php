<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Base de los modelos. Encapsula consultas preparadas sobre Database.
 */
abstract class Model
{
    protected string $table;

    /** @var array<string, mixed>|null */
    protected ?array $filtros = null;

    public function __construct(protected Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM {$this->table} WHERE id = ? LIMIT 1",
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $condiciones
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function all(array $condiciones = [], array $params = [], ?string $orden = null, ?int $limite = null): array
    {
        [$where, $params] = $this->construirWhere($condiciones, $params);

        $sql = "SELECT * FROM {$this->table}" . $where;

        if ($orden !== null) {
            $sql .= " ORDER BY {$orden}";
        }

        if ($limite !== null) {
            $sql .= ' LIMIT ' . (int) $limite;
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array<string, mixed> $condiciones
     * @param array<int|string, mixed> $params
     * @return array{0: string, 1: array<int|string, mixed>}
     */
    protected function construirWhere(array $condiciones, array $params = []): array
    {
        if ($condiciones === []) {
            return ['', $params];
        }

        $fragmentos = [];

        foreach ($condiciones as $columna => $valor) {
            if (is_array($valor)) {
                if ($valor === []) {
                    $fragmentos[] = '1 = 0';
                    continue;
                }
                $fragmentos[] = "{$columna} IN (" . implode(',', array_fill(0, count($valor), '?')) . ')';
                foreach ($valor as $v) {
                    $params[] = $v;
                }
                continue;
            }

            if ($valor === null) {
                $fragmentos[] = "{$columna} IS NULL";
                continue;
            }

            $fragmentos[] = "{$columna} = ?";
            $params[] = $valor;
        }

        return [' WHERE ' . implode(' AND ', $fragmentos), $params];
    }

    /**
     * @param array<int|string, mixed> $params
     */
    public function contar(string $condicionesSql = '1', array $params = []): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM {$this->table} WHERE {$condicionesSql}",
            $params
        );
    }
}
