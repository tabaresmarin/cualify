<?php
require_once __DIR__ . '/config.php';

/**
 * Consulta el rendimiento (Performance) de una URL usando Google PageSpeed Insights v5.
 * Devuelve ['ok' => bool, 'score' => int|null (0-100), 'error' => string|null].
 *
 * Requiere la variable de entorno PSI_API_KEY (ver .env.example).
 * Cómo generarla:
 *   1. Ve a https://console.cloud.google.com/ y crea o selecciona un proyecto.
 *   2. Menú > APIs & Services > Library > busca "PageSpeed Insights API" > Enable.
 *   3. APIs & Services > Credentials > Create Credentials > API key.
 *   4. (Recomendado) Restringe esa key a la API "PageSpeed Insights API".
 *   5. Copia la key en tu archivo .env como PSI_API_KEY=xxxxx
 */
function consultarRendimientoSitio($url) {
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

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45, // PSI puede tardar varios segundos en analizar el sitio
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $respuesta = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'score' => null, 'error' => 'Error de conexión: ' . $curlError];
    }

    $data = json_decode($respuesta, true);

    if ($httpCode !== 200 || !isset($data['lighthouseResult']['categories']['performance']['score'])) {
        $mensajeError = $data['error']['message'] ?? 'PageSpeed Insights no pudo analizar la URL proporcionada.';
        return ['ok' => false, 'score' => null, 'error' => $mensajeError];
    }

    $score = (int) round($data['lighthouseResult']['categories']['performance']['score'] * 100);

    return ['ok' => true, 'score' => $score, 'error' => null];
}

/**
 * Clasifica al lead según el puntaje de rendimiento obtenido.
 * Devuelve un array con la clave de clasificación y los textos a mostrar.
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
