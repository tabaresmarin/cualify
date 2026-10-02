<?php
/**
 * catalog.php — Búsqueda y gestión de productos del catálogo.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Busca productos por nombre, SKU o descripción.
 * Devuelve los N mejores matches.
 *
 * @param string $query    Término de búsqueda (ej. "sitio web", "tienda")
 * @param int    $clientId ID del cliente
 * @param PDO    $pdo
 * @param int    $limit    Máximo de resultados
 * @return array
 */
function buscarProductos(string $query, int $clientId, PDO $pdo, int $limit = 5): array {
    $query = trim($query);
    if ($query === '') {
        return [];
    }

    // Detectar idioma: si hay caracteres latinos comunes, asumir español
    $campoNombre = 'name_es';
    $campoDesc   = 'description_es';

    try {
        // Búsqueda con LIKE (más compatible que FULLTEXT en hostings compartidos)
        $terminos = preg_split('/\s+/', $query);
        $whereParts = [];
        $params = [$clientId];

        foreach ($terminos as $term) {
            $whereParts[] = "($campoNombre LIKE ? OR $campoDesc LIKE ? OR sku LIKE ?)";
            $like = '%' . $term . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where = implode(' AND ', $whereParts);
        $sql = "SELECT id, sku, name_es, name_en, description_es, category,
                       price_unit, currency, unit, min_qty, image_url
                FROM products
                WHERE client_id = ? AND active = 1 AND $where
                ORDER BY price_unit ASC
                LIMIT " . (int) $limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[buscarProductos] Error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene un producto por su ID o SKU.
 */
function obtenerProducto(int $clientId, PDO $pdo, ?int $productId = null, ?string $sku = null): ?array {
    try {
        if ($productId !== null) {
            $stmt = $pdo->prepare(
                "SELECT * FROM products WHERE id = ? AND client_id = ? AND active = 1 LIMIT 1"
            );
            $stmt->execute([$productId, $clientId]);
        } elseif ($sku !== null) {
            $stmt = $pdo->prepare(
                "SELECT * FROM products WHERE sku = ? AND client_id = ? AND active = 1 LIMIT 1"
            );
            $stmt->execute([$sku, $clientId]);
        } else {
            return null;
        }

        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        return $prod ?: null;
    } catch (PDOException $e) {
        error_log('[obtenerProducto] Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Lista productos por categoría (útil para mostrar catálogo completo).
 */
function listarCategorias(int $clientId, PDO $pdo): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT category, COUNT(*) AS total
             FROM products
             WHERE client_id = ? AND active = 1 AND category IS NOT NULL
             GROUP BY category
             ORDER BY category ASC"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[listarCategorias] Error: ' . $e->getMessage());
        return [];
    }
}