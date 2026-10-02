<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/gemini_api.php';

/**
 * Procesa un mensaje entrante usando Gemini como motor conversacional.
 */
function procesarConIA(string $phone, string $mensaje, PDO $pdo): string {
    // 1. Obtener historial
    $historial = obtenerHistorial($phone, $pdo, 3);

    // 2. Construir system prompt
    $systemPrompt = construirSystemPrompt($pdo);

    // 3. Construir "contents"
    $contents = [];
    foreach ($historial as $msg) {
        $contents[] = [
            'role'  => $msg['role'] === 'user' ? 'user' : 'model',
            'parts' => [['text' => $msg['content']]],
        ];
    }
    $contents[] = [
        'role'  => 'user',
        'parts' => [['text' => "MENSAJE ACTUAL DEL USUARIO (responde solo a esto): " . $mensaje]],
    ];

    // 4. Guardar el mensaje del usuario ANTES de llamar a Gemini
    guardarEnHistorial($phone, 'user', $mensaje, $pdo);

    // 5. Definir herramientas
    $tools = definirHerramientas();

    // 6. Llamar a Gemini
    try {
        $client    = new GeminiClient();
        $respuesta = $client->generateContent($contents, $tools, $systemPrompt);
    } catch (Throwable $e) {
        error_log('[ia_engine] Error Gemini: ' . $e->getMessage());
        return "Disculpa, tuve un problema técnico procesando tu mensaje. ¿Podrías intentarlo de nuevo?";
    }
    
    // 7. Procesar function calls si las hay
    $functionCalls = $client->extraerFunctionCalls($respuesta);
    if (!empty($functionCalls)) {
        return procesarFunctionCalls(
            $functionCalls,
            $phone,
            $pdo,
            $contents,
            $client,
            $systemPrompt,
            $respuesta  // Pasar la respuesta completa
        );
    }

    // 8. Extraer texto
    $texto = $client->extraerTexto($respuesta) ?? 'No pude entender tu mensaje. ¿Podrías reformularlo?';

    // 9. Guardar la respuesta del modelo
    guardarEnHistorial($phone, 'model', $texto, $pdo);

    return $texto;
}

/**
 * Obtiene el historial de conversación de un lead.
 */
function obtenerHistorial(string $phone, PDO $pdo, int $limite = 10): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT role, content FROM conversation_history
             WHERE phone = ? ORDER BY id DESC LIMIT $limite"
        );
        $stmt->execute([$phone]);
        $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_reverse($filas);
    } catch (PDOException $e) {
        error_log('[obtenerHistorial] Error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Guarda un mensaje en el historial.
 */
function guardarEnHistorial(string $phone, string $role, string $content, PDO $pdo): void {
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO conversation_history (phone, role, content, created_at)
             VALUES (?, ?, ?, NOW())"
        );
        $stmt->execute([$phone, $role, $content]);

        // Limpieza: mantener solo los últimos 20 mensajes por lead
        $stmt = $pdo->prepare(
            "DELETE FROM conversation_history
             WHERE phone = ?
             AND id NOT IN (
                 SELECT id FROM (
                     SELECT id FROM conversation_history
                     WHERE phone = ? ORDER BY id DESC LIMIT 20
                 ) AS t
             )"
        );
        $stmt->execute([$phone, $phone]);
    } catch (PDOException $e) {
        error_log('[guardarEnHistorial] Error: ' . $e->getMessage());
    }
}

/**
 * Construye el system prompt dinámico para EISO.
 */
function construirSystemPrompt(PDO $pdo): array {
    $prompt = "Eres el asistente de ventas de EISO, una empresa colombiana especializada en desarrollo web, "
            . "tiendas virtuales, marketing digital y actualización de sitios.\n\n"

            . "REGLAS CRÍTICAS:\n"
            . "1. Responde SIEMPRE en el idioma del usuario (español o inglés).\n"
            . "2. Sé amable, profesional y conciso.\n"
            . "3. 🔴 CRÍTICO: Responde ÚNICAMENTE al ÚLTIMO mensaje del usuario. "
            . "   NO incluyas productos, servicios o cantidades mencionadas en mensajes anteriores. "
            . "   Si el usuario cambió de tema, empieza de cero.\n"
            . "4. Cuando el usuario pida cotizar o preguntar precios:\n"
            . "   a. Extrae los productos y cantidades EXACTOS del mensaje actual.\n"
            . "   b. Usa 'buscar_producto' para cada producto mencionado.\n"
            . "   c. Usa 'cotizar_producto' con los SKUs y cantidades EXACTOS del mensaje actual.\n"
            . "5. NUNCA inventes precios ni SKUs. Usa solo los que devuelve 'buscar_producto'.\n"
            . "6. Si no encuentras el producto, ofrece pasar con un asesor.\n"
            . "7. Si el usuario quiere agendar, indícale que escriba AGENDAR.\n"
            . "8. 🔴 Si el usuario envía una URL, usa 'enviar_url'. "
            . "   La función te devolverá una puntuación de rendimiento rápida (basada en datos de usuarios "
            . "   reales de Chrome). Usa esa puntuación para iniciar la conversación de forma inmediata. "
            . "   Menciona que estás realizando un análisis más profundo en segundo plano y que le enviarás "
            . "   los resultados completos en unos minutos. "
            . "   NO esperes a que termine el análisis profundo para responder; sé ágil y conversacional.\n"
            . "9. Si el usuario pide hablar con un humano, indícale que escriba ASESOR.\n"
            . "10. Mantén un tono cercano y consultivo. Haz preguntas abiertas para entender las necesidades "
            . "    del cliente (objetivos del sitio, presupuesto aproximado, plazo de entrega) antes de ofrecer "
            . "    una cotización formal.";

    return ['parts' => [['text' => $prompt]]];
}

/**
 * Define las herramientas (function declarations) para Gemini.
 */
function definirHerramientas(): array {
    return [
        [
            'functionDeclarations' => [
                [
                    'name'        => 'buscar_producto',
                    'description' => 'Busca productos o servicios en el catálogo de EISO por nombre, categoría o palabra clave. Úsala cuando el usuario pregunte por un producto o servicio específico.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'query' => [
                                'type'        => 'string',
                                'description' => 'Término de búsqueda. Ej: "sitio web", "tienda virtual", "SEO"',
                            ],
                        ],
                        'required' => ['query'],
                    ],
                ],
                [
                    'name'        => 'cotizar_producto',
                    'description' => 'Calcula una cotización formal con precios, descuentos e impuestos. Úsala DESPUÉS de haber identificado productos con buscar_producto, o cuando el usuario diga "quiero cotizar X" con claridad.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'items' => [
                                'type'        => 'array',
                                'description' => 'Lista de productos a cotizar',
                                'items'       => [
                                    'type'       => 'object',
                                    'properties' => [
                                        'sku' => ['type' => 'string', 'description' => 'SKU del producto'],
                                        'qty' => ['type' => 'integer', 'description' => 'Cantidad'],
                                    ],
                                    'required' => ['sku', 'qty'],
                                ],
                            ],
                        ],
                        'required' => ['items'],
                    ],
                ],
                [
                    'name'        => 'agendar_cita',
                    'description' => 'Agenda una cita con un asesor de EISO.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'fecha' => ['type' => 'string', 'description' => 'Fecha en formato YYYY-MM-DD'],
                            'hora'  => ['type' => 'string', 'description' => 'Hora en formato HH:MM'],
                        ],
                        'required' => [],
                    ],
                ],
                [
                    'name'        => 'enviar_url',
                    'description' => 'Registra la URL de un sitio web para análisis de rendimiento.',
                    'parameters'  => [
                        'type'       => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'description' => 'URL del sitio web a analizar'],
                        ],
                        'required' => ['url'],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Procesa las function calls devueltas por Gemini.
 */
/**
 * Procesa las function calls devueltas por Gemini.
 */
/**
 * Procesa las function calls devueltas por Gemini.
 *
 * OPTIMIZACIÓN: Si una función devuelve el campo 'respuesta_directa',
 * se usa ESE texto como respuesta final, sin pedirle a Gemini que lo repita.
 * Esto ahorra tokens y evita el problema del "Procesé tu solicitud".
 */
/**
 * Procesa las function calls devueltas por Gemini.
 *
 * IMPORTANTE: Gemini puede hacer MÚLTIPLES rondas de function calling:
 *   Ronda 1: buscar_producto → ver catálogo
 *   Ronda 2: cotizar_producto → generar cotización
 *   Ronda 3: devolver texto final
 *
 * Este bucle itera hasta que Gemini devuelva texto, o hasta un máximo
 * de iteraciones (para evitar bucles infinitos).
 */
function procesarFunctionCalls(
    array $calls,
    string $phone,
    PDO $pdo,
    array $contents,
    GeminiClient $client,
    array $systemPrompt,
    array $respuestaOriginal
): string {
    $maxIteraciones = 5;   // Máximo de rondas de function calling
    $iteracion = 0;

    // Añadir el content inicial del modelo (con thoughtSignature)
    $contentModelo = $respuestaOriginal['candidates'][0]['content'] ?? null;
    if ($contentModelo !== null) {
        $contents[] = $contentModelo;
    }

    // Copia de calls para la primera iteración
    $callsActuales = $calls;

    while ($iteracion < $maxIteraciones) {
        $iteracion++;
        error_log("[ia_engine] ===== Iteración $iteracion de function calling =====");

        $respuestaDirecta = null;

        // 1. Ejecutar todas las function calls de esta ronda
        foreach ($callsActuales as $call) {
            $nombre = $call['name'] ?? '';
            $args   = $call['args'] ?? [];

            error_log("[ia_engine] Iteración $iteracion — Function call: $nombre " . json_encode($args));

            try {
                $resultado = match ($nombre) {
                    'buscar_producto'  => ejecutarBuscarProducto($args, $phone, $pdo),
                    'cotizar_producto' => ejecutarCotizarProducto($args, $phone, $pdo),
                    'agendar_cita'     => ejecutarAgendarCita($args, $phone, $pdo),
                    'enviar_url'       => ejecutarEnviarUrl($args, $phone, $pdo),
                    default            => ['status' => 'error', 'mensaje' => "Función desconocida: $nombre"],
                };
            } catch (Throwable $e) {
                error_log("[ia_engine] Error ejecutando $nombre: " . $e->getMessage());
                $resultado = ['status' => 'error', 'mensaje' => 'Error ejecutando la función: ' . $e->getMessage()];
            }

            // Si hay respuesta_directa, la guardamos pero seguimos el bucle
            // (por si hay más calls en la misma ronda)
            if (!empty($resultado['respuesta_directa'])) {
                $respuestaDirecta = $resultado['respuesta_directa'];
                error_log("[ia_engine] Respuesta directa capturada (" . strlen($respuestaDirecta) . " chars)");
            }

            // Añadir el resultado al historial de Gemini
            $contents[] = [
                'role'  => 'user',
                'parts' => [[
                    'functionResponse' => [
                        'name'     => $nombre,
                        'response' => $resultado,
                    ],
                ]],
            ];
        }

        // Si hay respuesta directa, devolverla inmediatamente
        if ($respuestaDirecta !== null) {
            error_log("[ia_engine] Devolviendo respuesta_directa en iteración $iteracion");
            guardarEnHistorial($phone, 'model', $respuestaDirecta, $pdo);
            return $respuestaDirecta;
        }

        // 2. Llamar a Gemini de nuevo con los resultados de las functions
        try {
            $respuestaSiguiente = $client->generateContent($contents, [], $systemPrompt);
        } catch (Throwable $e) {
            error_log("[ia_engine] Error llamando a Gemini en iteración $iteracion: " . $e->getMessage());
            return 'Hubo un problema procesando tu solicitud. Por favor, intenta de nuevo.';
        }

        // 3. Ver si Gemini devolvió texto (fin del bucle)
        $texto = $client->extraerTexto($respuestaSiguiente);
        if ($texto !== null && $texto !== '') {
            error_log("[ia_engine] Gemini devolvió texto en iteración $iteracion: " . substr($texto, 0, 100));
            guardarEnHistorial($phone, 'model', $texto, $pdo);
            return $texto;
        }

        // 4. Ver si Gemini devolvió MÁS function calls (continuar el bucle)
        $nuevasCalls = $client->extraerFunctionCalls($respuestaSiguiente);
        if (empty($nuevasCalls)) {
            // No hay texto ni más function calls: respuesta vacía inesperada
            error_log("[ia_engine] Iteración $iteracion: Gemini sin texto ni function calls");
            error_log("[ia_engine] Respuesta completa: " . substr(json_encode($respuestaSiguiente), 0, 500));
            return 'Déjame procesar eso y te confirmo en un momento.';
        }

        // Añadir el content del modelo a la conversación para la siguiente ronda
        $contentSiguiente = $respuestaSiguiente['candidates'][0]['content'] ?? null;
        if ($contentSiguiente !== null) {
            $contents[] = $contentSiguiente;
        }

        // Preparar la siguiente ronda
        $callsActuales = $nuevasCalls;
        error_log("[ia_engine] Iteración $iteracion completada. Nueva ronda con " . count($nuevasCalls) . " function calls.");
    }

    // Se agotaron las iteraciones
    error_log("[ia_engine] Se agotaron las $maxIteraciones iteraciones sin obtener texto");
    return 'Estoy procesando tu solicitud, pero está tomando más tiempo del esperado. ¿Puedes confirmarme qué productos te interesan?';
}
// ============================================================================
// EJECUTORES DE HERRAMIENTAS
// ============================================================================

function ejecutarAgendarCita(array $args, string $phone, PDO $pdo): array {
    // Delegamos al flujo existente de slots (que ya tienes implementado)
    // Por ahora solo devolvemos un mensaje para que el usuario escriba AGENDAR
    return [
        'status'  => 'ok',
        'mensaje' => 'El usuario debe escribir AGENDAR para ver los horarios disponibles.',
    ];
}

/**
 * Calcula una cotización real con precios, descuentos e impuestos.
 */
function ejecutarCotizarProducto(array $args, string $phone, PDO $pdo): array {
    require_once __DIR__ . '/quoter.php';

    $items    = $args['items'] ?? [];
    $clientId = 1;

    if (empty($items)) {
        return ['status' => 'error', 'mensaje' => 'No se especificaron productos para cotizar.'];
    }

    $itemsNormalizados = [];
    foreach ($items as $item) {
        if (!empty($item['sku'])) {
            $itemsNormalizados[] = [
                'sku' => $item['sku'],
                'qty' => (int) ($item['qty'] ?? 1),
            ];
        }
    }

    $resultado = calcularCotizacion($itemsNormalizados, $clientId, $pdo);

    if (!$resultado['ok']) {
        return [
            'status'  => 'error',
            'mensaje' => 'No pude calcular la cotización: ' . ($resultado['error'] ?? 'error desconocido'),
        ];
    }

    $quote   = $resultado['quote'];
    $quoteId = guardarCotizacion($clientId, $phone, $quote, $pdo);

    $textoFormateado = formatearCotizacionTexto($quote, $quoteId);

    require_once __DIR__ . '/lead_qualifier.php';
    actualizarLead($pdo, $phone, ['step' => '2']);

    // ⭐ Limpiar el historial para que el próximo mensaje empiece "fresco"
    limpiarHistorial($phone, $pdo);

    return [
        'status'            => 'ok',
        'quote_id'          => $quoteId,
        'total'             => $quote['total'],
        'currency'          => $quote['currency'],
        'respuesta_directa' => $textoFormateado,
    ];
}

function ejecutarEnviarUrl(array $args, string $phone, PDO $pdo): array {
    $url = $args['url'] ?? '';
    if (empty($url)) {
        return ['status' => 'error', 'mensaje' => 'No se recibió una URL válida.'];
    }

    require_once __DIR__ . '/lead_qualifier.php';
    $url = normalizarUrlSitio($url);
    if ($url === null) {
        return ['status' => 'error', 'mensaje' => 'La URL no es válida.'];
    }

    encolarTrabajoPagespeed($pdo, $phone, $url, []);

    return [
        'status'            => 'ok',
        'url'               => $url,
        'respuesta_directa' => "Perfecto, recibí tu sitio: {$url}\n\n"
                             . "Estoy analizando su rendimiento. Te enviaré el diagnóstico en un momento... ⏳",
    ];
}

// ============================================================================
// EJECUTORES DE HERRAMIENTAS — IMPLEMENTACIÓN REAL
// ============================================================================

/**
 * Busca productos en el catálogo del cliente.
 */
function ejecutarBuscarProducto(array $args, string $phone, PDO $pdo): array {
    require_once __DIR__ . '/catalog.php';

    $query    = $args['query'] ?? '';
    $clientId = 1; // TODO: obtener dinámicamente si es multi-cliente

    if ($query === '') {
        return ['status' => 'error', 'mensaje' => 'No se proporcionó término de búsqueda.'];
    }

    $productos = buscarProductos($query, $clientId, $pdo, 5);

    if (empty($productos)) {
        return [
            'status'  => 'ok',
            'count'   => 0,
            'mensaje' => "No encontramos productos relacionados con '{$query}'. "
                       . "¿Puedes describirlo de otra forma o escribir ASESOR para hablar con un humano?",
        ];
    }

    // Formatear para el modelo
    $formateados = array_map(function ($p) {
        return [
            'sku'          => $p['sku'],
            'name'         => $p['name_es'],
            'description'  => mb_substr($p['description_es'] ?? '', 0, 150),
            'category'     => $p['category'],
            'price'        => (float) $p['price_unit'],
            'currency'     => $p['currency'],
            'unit'         => $p['unit'],
        ];
    }, $productos);

    return [
        'status'    => 'ok',
        'count'     => count($formateados),
        'productos' => $formateados,
        'mensaje'   => "Encontré " . count($formateados) . " producto(s). Muéstralos al usuario de forma atractiva y pregunta cuál(es) y qué cantidad le interesa(n).",
    ];
}

/**
 * Limpia el historial de conversación de un lead.
 * Útil después de enviar una cotización para evitar que el próximo
 * mensaje mezcle productos de conversaciones anteriores.
 */
function limpiarHistorial(string $phone, PDO $pdo): void {
    try {
        $stmt = $pdo->prepare("DELETE FROM conversation_history WHERE phone = ?");
        $stmt->execute([$phone]);
        error_log("[limpiarHistorial] Historial limpiado para $phone");
    } catch (PDOException $e) {
        error_log('[limpiarHistorial] Error: ' . $e->getMessage());
    }
}