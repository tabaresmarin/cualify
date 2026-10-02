<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

/**
 * Envoltura delgada sobre PDO. Una sola conexion por proceso (singleton),
 * con prepared statements nativos y excepciones en modo error.
 */
final class Database
{
    private static ?self $instance = null;

    private ?PDO $pdo = null;

    /** @param array<string, mixed> $config */
    public function __construct(private array $config)
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self([]);
    }

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['port'],
            $this->config['name'],
            $this->config['charset']
        );

        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException(
                'No se pudo conectar a la base de datos: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        return $this->pdo;
    }

    /** @param array<string|int, mixed> $params */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * @param array<string|int, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $fila = $this->query($sql, $params)->fetch();
        return $fila === false ? null : $fila;
    }

    /** @param array<string|int, mixed> $params */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $valor = $this->query($sql, $params)->fetchColumn();
        return $valor === false ? null : $valor;
    }

    /** @param array<string|int, mixed> $params */
    public function execute(string $sql, array $params = []): bool
    {
        return $this->query($sql, $params)->rowCount() >= 0;
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * ¿Existe la tabla en esta base?
     *
     * El schema local y el de produccion no coinciden (ver README): hay
     * instalaciones donde faltan conversation_history o pagespeed_cache. Permite
     * que las consultas opcionales devuelvan vacío en vez de reventar la
     * pagina entera con un SQLSTATE 42S02.
     */
    public function tableExists(string $tabla): bool
    {
        $nombre = preg_replace('/[^a-zA-Z0-9_]/', '', $tabla) ?? '';

        if ($nombre === '') {
            return false;
        }

        $existe = $this->fetchValue(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$nombre]
        );

        return (int) $existe > 0;
    }

    /**
     * Ejecuta una consulta y devuelve el valor por defecto si la tabla no
     * existe. Para agregaciones del panel sobre schema opcional.
     */
    public function fetchValueSiExiste(string $sql, array $params, mixed $porDefecto = null): mixed
    {
        try {
            return $this->fetchValue($sql, $params);
        } catch (\PDOException $e) {
            if ($this->esTablaAusente($e)) {
                return $porDefecto;
            }

            throw $e;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function fetchAllSiExiste(string $sql, array $params = []): array
    {
        try {
            return $this->fetchAll($sql, $params);
        } catch (\PDOException $e) {
            if ($this->esTablaAusente($e)) {
                return [];
            }

            throw $e;
        }
    }

    private function esTablaAusente(\PDOException $e): bool
    {
        // 42S02 = base/table not found (MariaDB). 1146 = MySQL equivalente.
        $code = (string) $e->getCode();

        return str_starts_with($code, '42S02') || $code === '1146';
    }
}
