<?php
/**
 * crux_api.php — Consulta rápida de Core Web Vitals vía API CrUX.
 *
 * Devuelve una evaluación inmediata del rendimiento basada en datos de usuarios reales.
 * No ejecuta Lighthouse, por lo que la respuesta es casi instantánea.
 */

require_once __DIR__ . '/config.php';

/**
 * Consulta la API de CrUX para obtener métricas de rendimiento rápidas.
 *
 * @param string $url      URL del sitio a consultar.
 * @param string $apiKey   API Key de Google Cloud (puede ser la misma que PSI_API_KEY).
 * @return array           ['ok' => bool, 'score' => int|null, 'metrics' => array, 'error' => string|null]
 */
function consultarRendimientoRapidoConCrUX(string $url, string $apiKey): array {
    if (empty($apiKey)) {
        return ['ok' => false, 'error' => 'Falta la API Key de CrUX.'];
    }

    $endpoint = 'https://chromeuxreport.googleapis.com/v1/records:queryRecord?key=' . $apiKey;

    // Construir el cuerpo de la petición.
    // Se solicita un resumen para todas las plataformas (sin especificar formFactor)
    // y las tres métricas principales de Core Web Vitals.
    $payload = [
        'url' => $url,
        'metrics' => [
            'largest_contentful_paint',
            'interaction_to_next_paint',
            'cumulative_layout_shift',
        ],
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15, // La API CrUX es rápida, 15s es más que suficiente.
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Error de conexión con CrUX: ' . $curlError];
    }

    $data = json_decode($response, true);

    // La API CrUX devuelve 404 si no hay suficientes datos para la URL.
    // En ese caso, devolvemos un resultado controlado.
    if ($httpCode === 404 || !isset($data['record'])) {
        return [
            'ok'      => true,
            'score'   => null,
            'metrics' => [],
            'error'   => 'No hay suficientes datos de usuarios reales para este sitio. Se necesitan más visitas para generar métricas.',
        ];
    }

    if ($httpCode !== 200) {
        $errorMsg = $data['error']['message'] ?? 'Error desconocido en CrUX.';
        return ['ok' => false, 'error' => "Error de CrUX (HTTP $httpCode): $errorMsg"];
    }

    // Extraer las métricas de interés.
    $metrics = $data['record']['metrics'] ?? [];
    $resultado = [
        'ok'      => true,
        'score'   => null, // CrUX no devuelve una puntuación directa.
        'metrics' => [
            'lcp'  => $metrics['largest_contentful_paint']['percentiles']['p75'] ?? null,
            'inp'  => $metrics['interaction_to_next_paint']['percentiles']['p75'] ?? null,
            'cls'  => $metrics['cumulative_layout_shift']['percentiles']['p75'] ?? null,
        ],
        'error' => null,
    ];

    // Calcular una puntuación simple (0-100) a partir de los umbrales de Web Vitals.
    // Esta puntuación es orientativa para la respuesta rápida.
    $resultado['score'] = calcularPuntuacionWebVitals(
        $resultado['metrics']['lcp'],
        $resultado['metrics']['inp'],
        $resultado['metrics']['cls']
    );

    return $resultado;
}

/**
 * Calcula una puntuación orientativa de 0 a 100 basada en los umbrales de Core Web Vitals.
 */
function calcularPuntuacionWebVitals(?int $lcpMs, ?int $inpMs, ?string $cls): ?int {
    if ($lcpMs === null && $inpMs === null && $cls === null) {
        return null;
    }

    $puntos = 0;
    $total  = 0;

    // LCP: Bueno <= 2500ms, Necesita mejora <= 4000ms, Malo > 4000ms[reference:2].
    if ($lcpMs !== null) {
        $total++;
        if ($lcpMs <= 2500) $puntos += 100;
        elseif ($lcpMs <= 4000) $puntos += 50;
        // Malo = 0 puntos
    }

    // INP (sucesor de FID): Bueno <= 200ms, Necesita mejora <= 500ms, Malo > 500ms.
    if ($inpMs !== null) {
        $total++;
        if ($inpMs <= 200) $puntos += 100;
        elseif ($inpMs <= 500) $puntos += 50;
    }

    // CLS: Bueno <= 0.1, Necesita mejora <= 0.25, Malo > 0.25[reference:3].
    if ($cls !== null) {
        $total++;
        $clsVal = (float) $cls;
        if ($clsVal <= 0.1) $puntos += 100;
        elseif ($clsVal <= 0.25) $puntos += 50;
    }

    return $total > 0 ? (int) round($puntos / $total) : null;
}