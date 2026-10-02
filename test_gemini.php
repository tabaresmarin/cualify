<?php
// test_gemini.php
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/gemini_api.php';

// 1. Definir el system prompt (el "cerebro" del asistente)
$systemPrompt = [
    'parts' => [[
        'text' => "Eres el asistente de ventas de EISO, una empresa colombiana especializada en desarrollo web, tiendas virtuales, marketing digital y actualización de sitios. "
                . "Tu objetivo es ayudar a los clientes a entender nuestros servicios, cotizar proyectos y agendar asesorías. "
                . "Responde SIEMPRE en el idioma del usuario (español o inglés). "
                . "Sé amable, profesional y conciso. No inventes precios ni servicios que no existan. "
                . "Si el usuario quiere agendar una cita, indícale que escriba 'AGENDAR'. "
                . "Si el usuario envía una URL, indícale que la recibiste y que el análisis se hará en breve. "
                . "Si el usuario pregunta por precios, indícale que un asesor le dará una cotización personalizada."
    ]]
];

// 2. Mensaje del usuario
$mensaje = 'Hola, ¿qué servicios ofrecen?';

$contents = [
    [
        'role'  => 'user',
        'parts' => [['text' => $mensaje]],
    ]
];

// 3. Llamar a Gemini con el system prompt
try {
    $client = new GeminiClient();
    $respuesta = $client->generateContent($contents, [], $systemPrompt);
    $texto = $client->extraerTexto($respuesta);

    echo "Mensaje del usuario: $mensaje\n\n";
    echo "Respuesta del asistente:\n";
    echo $texto;
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}