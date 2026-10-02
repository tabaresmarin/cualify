<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Consulta el rendimiento (Performance) de una URL usando Google PageSpeed Insights v5.
 *
 * CON CACHÉ: Si la URL fue analizada en las últimas 24h, devuelve el resultado
 * guardado sin llamar a la API.
 *
 * Devuelve ['ok' => bool, 'score' => int|null, 'error' => string|null, 'from_cache' => bool].
 */
function consultarRendimientoSitio($url, $usarCache = true) {
    // 1. Verificar caché primero
    if ($usarCache) {
        $cache = obtenerDeCachePageSpeed($url);
        if ($cache !== null) {
            error_log("[consultarRendimientoSitio] Cache HIT para $url (score: {$cache['score']})");
            return [
                'ok'         => true,
                'score'      => $cache['score'],
                'error'      => null,
                'from_cache' => true,
            ];
        }
        error_log("[consultarRendimientoSitio] Cache MISS para $url");
    }

    // 2. Llamar a PSI
    $apiKey = valorEntorno('PSI_API_KEY');
    if (!$apiKey) {
        return ['ok' => false, 'score' => null, 'error' => 'Falta configurar PSI_API_KEY en .env', 'from_cache' => false];
    }

    $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
        'url'      => $url,
        'key'      => $apiKey,
        'category' => 'performance',
        'strategy' => 'mobile',
    ]);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $respuesta = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'score' => null, 'error' => 'Error de conexión: ' . $curlError, 'from_cache' => false];
    }

    $data = json_decode($respuesta, true);

    if ($httpCode !== 200 || !isset($data['lighthouseResult']['categories']['performance']['score'])) {
        $mensajeError = $data['error']['message'] ?? 'PageSpeed Insights no pudo analizar la URL proporcionada.';
        return ['ok' => false, 'score' => null, 'error' => $mensajeError, 'from_cache' => false];
    }

    $score = (int) round($data['lighthouseResult']['categories']['performance']['score'] * 100);

    // 3. Guardar en caché
    guardarEnCachePageSpeed($url, $score, $data);

    return ['ok' => true, 'score' => $score, 'error' => null, 'from_cache' => false];
}

/**
 * Busca un resultado en la caché de PageSpeed.
 * Devuelve ['score' => int, 'created_at' => string] o null si no hay o expiró.
 */
function obtenerDeCachePageSpeed(string $url): ?array {
    $urlHash = hash('sha256', $url);
    try {
        $pdo = obtenerConexion();
        $stmt = $pdo->prepare(
            "SELECT score, created_at FROM pagespeed_cache
             WHERE url_hash = ? AND strategy = 'mobile' AND expires_at > NOW()
             LIMIT 1"
        );
        $stmt->execute([$urlHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (PDOException $e) {
        error_log('[obtenerDeCachePageSpeed] Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Guarda un resultado de PageSpeed en caché por 24 horas.
 */
function guardarEnCachePageSpeed(string $url, int $score, ?array $rawResponse = null): void {
    $urlHash = hash('sha256', $url);
    try {
        $pdo = obtenerConexion();
        $stmt = $pdo->prepare(
            "INSERT INTO pagespeed_cache (url_hash, url, strategy, score, raw_response, created_at, expires_at)
             VALUES (?, ?, 'mobile', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 24 HOUR))
             ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                raw_response = VALUES(raw_response),
                created_at = NOW(),
                expires_at = VALUES(expires_at)"
        );
        $stmt->execute([
            $urlHash,
            $url,
            $score,
            $rawResponse ? json_encode($rawResponse, JSON_UNESCAPED_UNICODE) : null,
        ]);
        error_log("[guardarEnCachePageSpeed] Guardado en caché: $url (score: $score)");
    } catch (PDOException $e) {
        error_log('[guardarEnCachePageSpeed] Error: ' . $e->getMessage());
    }
}

/**
 * Clasifica al lead según el puntaje.
 * (Sin cambios)
 */
function clasificarPorRendimiento($score) {
    if ($score <= 50) {
        return [
            'clave'   => 'muy_necesitado',
            'etiqueta'=> 'Cliente muy necesitado',
            'mensaje' => "Tu sitio obtuvo *{$score}/100* en rendimiento móvil. Esto significa que probablemente estás perdiendo visitantes y ventas por tiempos de carga lentos. Es momento de renovarlo cuanto antes.\n\nTe recomendamos agendar una llamada con uno de nuestros asesores para revisar un plan de renovación.",
        ];
    } elseif ($score <= 70) {
        return [
            'clave'   => 'oportunidad_mejora',
            'etiqueta'=> 'Oportunidad de mejorar',
            'mensaje' => "Tu sitio obtuvo *{$score}/100* en rendimiento móvil. Vas por buen camino, pero hay una oportunidad clara de mejorar la velocidad y experiencia de tus usuarios.\n\n¿Te gustaría agendar una llamada para ver cómo podemos aumentar esa calificación?",
        ];
    } else {
        return [
            'clave'   => 'listo_marketing',
            'etiqueta'=> 'Listo para marketing digital',
            'mensaje' => "¡Excelente noticia! Tu sitio obtuvo *{$score}/100* en rendimiento móvil, un resultado sólido.\n\nCon esa base técnica ya estás listo para dar el siguiente paso: atraer más visitantes con una estrategia de marketing digital. ¿Quieres agendar una llamada para conversarlo?",
        ];
    }
}