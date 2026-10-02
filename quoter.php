<?php
/**
 * quoter.php — Cálculo de cotizaciones con reglas configurables.
 *
 * Soporta:
 *   - volume_discount: descuento por cantidad (tiers)
 *   - tax: impuesto porcentual (IVA, etc.)
 *   - shipping: costo de envío (fijo o gratis según monto)
 *   - fixed_discount: descuento fijo en monto
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Calcula una cotización completa a partir de una lista de items.
 *
 * @param array $items    [['product_id' => 1, 'qty' => 5], ['sku' => 'X', 'qty' => 2]]
 * @param int   $clientId
 * @param PDO   $pdo
 * @return array          ['ok' => bool, 'quote' => [...], 'error' => string|null]
 */
function calcularCotizacion(array $items, int $clientId, PDO $pdo): array {
    if (empty($items)) {
        return ['ok' => false, 'error' => 'No hay items en la cotización'];
    }

    // 1. Resolver productos y calcular subtotal
    $lineas = [];
    $subtotal = 0.0;
    $currency = 'COP';

    foreach ($items as $item) {
        $producto = null;

        if (!empty($item['product_id'])) {
            $producto = obtenerProducto($clientId, $pdo, (int) $item['product_id']);
        } elseif (!empty($item['sku'])) {
            $producto = obtenerProducto($clientId, $pdo, null, $item['sku']);
        }

        if (!$producto) {
            return ['ok' => false, 'error' => "Producto no encontrado: " . json_encode($item)];
        }

        $qty = max(1, (int) ($item['qty'] ?? 1));
        $lineTotal = (float) $producto['price_unit'] * $qty;
        $subtotal += $lineTotal;
        $currency = $producto['currency'];

        $lineas[] = [
            'product_id'   => (int) $producto['id'],
            'sku'          => $producto['sku'],
            'name'         => $producto['name_es'],
            'qty'          => $qty,
            'unit_price'   => (float) $producto['price_unit'],
            'line_total'   => $lineTotal,
            'unit'         => $producto['unit'],
            'category'     => $producto['category'],
        ];
    }

    // 2. Aplicar reglas (en orden de prioridad)
    $reglas = obtenerReglasActivas($clientId, $pdo);
    $descuento = 0.0;
    $impuesto = 0.0;
    $flete = 0.0;
    $reglasAplicadas = [];

    // Primero: descuentos por volumen y fijos
    foreach ($reglas as $regla) {
        if ($regla['rule_type'] === 'volume_discount') {
            $config = json_decode($regla['config_json'], true) ?: [];
            $tiers = $config['tiers'] ?? [];
            foreach ($tiers as $tier) {
                $minQty = (int) ($tier['min_qty'] ?? 1);
                $pct    = (float) ($tier['discount_pct'] ?? 0);
                $qtyTotal = array_sum(array_column($lineas, 'qty'));

                if ($qtyTotal >= $minQty && $pct > 0) {
                    $descVolumen = $subtotal * ($pct / 100);
                    $descuento += $descVolumen;
                    $reglasAplicadas[] = [
                        'nombre' => $regla['rule_name'],
                        'monto'  => $descVolumen,
                        'detalle'=> "{$pct}% por cantidad ({$qtyTotal} unidades)",
                    ];
                    break; // Solo aplica el mejor tier
                }
            }
        } elseif ($regla['rule_type'] === 'fixed_discount') {
            $config = json_decode($regla['config_json'], true) ?: [];
            $monto = (float) ($config['amount'] ?? 0);
            if ($monto > 0) {
                $descuento += $monto;
                $reglasAplicadas[] = [
                    'nombre' => $regla['rule_name'],
                    'monto'  => $monto,
                    'detalle'=> 'Descuento fijo',
                ];
            }
        }
    }

    // Asegurar que el descuento no supere el subtotal
    $descuento = min($descuento, $subtotal);
    $baseImponible = $subtotal - $descuento;

    // Segundo: impuestos
    foreach ($reglas as $regla) {
        if ($regla['rule_type'] === 'tax') {
            $config = json_decode($regla['config_json'], true) ?: [];
            $rate   = (float) ($config['rate'] ?? 0);
            $label  = $config['label'] ?? 'Impuesto';
            if ($rate > 0) {
                $imp = $baseImponible * $rate;
                $impuesto += $imp;
                $reglasAplicadas[] = [
                    'nombre' => $label,
                    'monto'  => $imp,
                    'detalle'=> ($rate * 100) . '% sobre ' . number_format($baseImponible, 0, ',', '.'),
                ];
            }
        }
    }

    // Tercero: envío
    foreach ($reglas as $regla) {
        if ($regla['rule_type'] === 'shipping') {
            $config = json_decode($regla['config_json'], true) ?: [];
            $amount   = (float) ($config['amount'] ?? 0);
            $freeAbove = (float) ($config['free_above'] ?? 0);
            $label    = $config['label'] ?? 'Envío';

            if ($amount > 0 && ($freeAbove === 0 || $baseImponible < $freeAbove)) {
                $flete += $amount;
                $reglasAplicadas[] = [
                    'nombre' => $label,
                    'monto'  => $amount,
                    'detalle'=> 'Envío',
                ];
            }
        }
    }

    $total = $baseImponible + $impuesto + $flete;

    return [
        'ok' => true,
        'quote' => [
            'items'             => $lineas,
            'subtotal'          => $subtotal,
            'discount'          => $descuento,
            'base_imponible'    => $baseImponible,
            'tax'               => $impuesto,
            'shipping'          => $flete,
            'total'             => $total,
            'currency'          => $currency,
            'rules_applied'     => $reglasAplicadas,
        ],
        'error' => null,
    ];
}

/**
 * Obtiene las reglas activas de un cliente, ordenadas por prioridad.
 */
function obtenerReglasActivas(int $clientId, PDO $pdo): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM quote_rules
             WHERE client_id = ? AND active = 1
             ORDER BY priority ASC"
        );
        $stmt->execute([$clientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[obtenerReglasActivas] Error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Guarda una cotización en la base de datos y devuelve su ID.
 */
function guardarCotizacion(int $clientId, string $phone, array $quote, PDO $pdo): ?int {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO quotes
             (client_id, phone, items_json, subtotal, discount, tax, shipping, total, currency, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())"
        );
        $stmt->execute([
            $clientId,
            $phone,
            json_encode($quote['items'], JSON_UNESCAPED_UNICODE),
            $quote['subtotal'],
            $quote['discount'],
            $quote['tax'],
            $quote['shipping'],
            $quote['total'],
            $quote['currency'],
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log('[guardarCotizacion] Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Formatea una cotización como texto para enviar por WhatsApp.
 */
function formatearCotizacionTexto(array $quote, ?int $quoteId = null): string {
    $simbolo = $quote['currency'] === 'COP' ? '$' : '$';
    $fmt = fn($n) => $simbolo . number_format($n, 0, ',', '.');

    $texto = "📋 *COTIZACIÓN" . ($quoteId ? " #{$quoteId}" : '') . "*\n";
    $texto .= str_repeat('─', 30) . "\n\n";

    // Items
    foreach ($quote['items'] as $item) {
        $texto .= "• *{$item['name']}*\n";
        $texto .= "  {$item['qty']} {$item['unit']} × {$fmt($item['unit_price'])} = *{$fmt($item['line_total'])}*\n\n";
    }

    $texto .= str_repeat('─', 30) . "\n";
    $texto .= "Subtotal: {$fmt($quote['subtotal'])}\n";

    if ($quote['discount'] > 0) {
        $texto .= "Descuento: -{$fmt($quote['discount'])}\n";
    }

    if ($quote['tax'] > 0) {
        $texto .= "IVA: {$fmt($quote['tax'])}\n";
    }

    if ($quote['shipping'] > 0) {
        $texto .= "Envío: {$fmt($quote['shipping'])}\n";
    }

    $texto .= str_repeat('─', 30) . "\n";
    $texto .= "*TOTAL: {$fmt($quote['total'])} {$quote['currency']}*\n\n";

    // Reglas aplicadas
    if (!empty($quote['rules_applied'])) {
        $texto .= "✨ *Beneficios aplicados:*\n";
        foreach ($quote['rules_applied'] as $regla) {
            $texto .= "• {$regla['nombre']} ({$regla['detalle']})\n";
        }
        $texto .= "\n";
    }

    $texto .= "📅 Cotización válida por 15 días.\n";
    $texto .= "¿Quieres agendar una llamada para revisar los detalles? Escribe *AGENDAR*.";

    return $texto;
}