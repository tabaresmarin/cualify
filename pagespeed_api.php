<?php
require_once __DIR__ . '/config.php';

/**
 * Consulta el rendimiento (Performance) de una URL usando Google PageSpeed Insights v5.
 * Devuelve ['ok' => bool, 'score' => int|null (0-100), 'error' => string|null].
 *
 * Incluye reintentos automáticos para mitigar timeouts transitorios de PSI.
 */
function consultarRendimientoSitio($url, $intentosMaximos = 2) {
    $apiKey = valorEntorno('PSI_API_KEY');
    if (!$apiKey) {
        return ['ok' => false, 'score' => null, 'error' => 'Falta configurar PSI_API_KEY en .env'];
    }

    $endpoint = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . http_build_query([
        'url'      => $url,
        'key'      => $apiKey,
        'category' => 'performance',
        'strategy' => 'mobile',
    ]);

    $ultimoError = null;

    for ($intento = 1; $intento <= $intentosMaximos; $intento++) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90, // Aumentado de 45 a 90 segundos
            CURLOPT_CONNECTTIMEOUT => 15, // Timeout de conexión inicial
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $respuesta = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Si hay error de conexión (timeout, DNS, etc.)
        if ($curlError) {
            $ultimoError = 'Error de conexión: ' . $curlError;
            error_log("[pagespeed_api] Intento $intento/$intentosMaximos falló para $url: $ultimoError");

            // Esperar 3 segundos antes de reintentar
            if ($intento < $intentosMaximos) {
                sleep(3);
            }
            continue;
        }

        $data = json_decode($respuesta, true);

        // Si la respuesta HTTP no es 200 o falta el score
        if ($httpCode !== 200 || !isset($data['lighthouseResult']['categories']['performance']['score'])) {
            $ultimoError = $data['error']['message'] ?? 'PageSpeed Insights no pudo analizar la URL.';

            // Si es un error 429 (rate limit) o 5xx (servidor Google), reintentar
            if (($httpCode === 429 || $httpCode >= 500) && $intento < $intentosMaximos) {
                error_log("[pagespeed_api] Intento $intento/$intentosMaximos: HTTP $httpCode, reintentando...");
                sleep(5);
                continue;
            }

            // Otros errores (400 URL inválida, etc.) no reintentar
            return ['ok' => false, 'score' => null, 'error' => $ultimoError];
        }

        // Éxito
        $score = (int) round($data['lighthouseResult']['categories']['performance']['score'] * 100);
        return ['ok' => true, 'score' => $score, 'error' => null];
    }

    // Se agotaron los intentos
    return ['ok' => false, 'score' => null, 'error' => $ultimoError ?? 'PageSpeed no respondió tras ' . $intentosMaximos . ' intentos.'];
}

/**
 * Clasifica al lead según el puntaje de rendimiento obtenido.
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