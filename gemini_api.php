<?php
require_once __DIR__ . '/config.php';

/**
 * Cliente para la API de Gemini (Google AI for Developers).
 * Usa el endpoint REST v1beta con cURL.
 *
 * Incluye:
 *   - Reintentos con backoff exponencial + jitter.
 *   - Fallback automático a un modelo "lite" si el principal falla.
 *   - Detección de errores transitorios (429, 5xx).
 */
class GeminiClient {
    private string $apiKey;
    private string $model;
    private string $fallbackModel;
    private string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models';
    private int $maxRetries = 4;

    public function __construct() {
        $this->apiKey        = valorEntorno('GEMINI_API_KEY');
        $this->model         = valorEntorno('GEMINI_MODEL') ?: 'gemini-3.8-flash';
        $this->fallbackModel = valorEntorno('GEMINI_FALLBACK_MODEL') ?: 'gemini-3.5-flash-lite';

        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY no está configurada en .env');
        }
    }

    // ========================================================================
    // MÉTODO PRINCIPAL
    // ========================================================================

    /**
     * Envía una conversación a Gemini y devuelve la respuesta.
     *
     * @param array $contents          Historial de mensajes en formato Gemini.
     * @param array $tools             Herramientas (function declarations).
     * @param array $systemInstruction Instrucciones del sistema.
     * @return array                   Respuesta decodificada de la API.
     */
    public function generateContent(array $contents, array $tools = [], array $systemInstruction = []): array {
        $payload = $this->construirPayload($contents, $tools, $systemInstruction);
        return $this->llamarConReintentos($payload);
    }

    // ========================================================================
    // EXTRACTORES (los que faltaban)
    // ========================================================================

    /**
     * Extrae el texto de la respuesta de Gemini.
     * Devuelve null si la respuesta no contiene texto.
     */
    public function extraerTexto(array $respuesta): ?string {
        if (!isset($respuesta['candidates'][0]['content']['parts'])) {
            return null;
        }

        $textoCompleto = '';
        foreach ($respuesta['candidates'][0]['content']['parts'] as $part) {
            if (isset($part['text'])) {
                $textoCompleto .= $part['text'];
            }
        }

        return $textoCompleto !== '' ? $textoCompleto : null;
    }

    /**
     * Extrae las llamadas a funciones (function calls) de la respuesta.
     * Devuelve un array de function calls (puede estar vacío).
     */
    public function extraerFunctionCalls(array $respuesta): array {
        $parts = $respuesta['candidates'][0]['content']['parts'] ?? [];
        $calls = [];

        foreach ($parts as $part) {
            if (isset($part['functionCall'])) {
                $calls[] = $part['functionCall'];
            }
        }

        return $calls;
    }

    // ========================================================================
    // MÉTODOS PRIVADOS DE ORQUESTACIÓN
    // ========================================================================

    /**
     * Orquesta los reintentos con backoff exponencial y fallback de modelo.
     */
    private function llamarConReintentos(array $payload): array {
        $intento      = 0;
        $modeloActual = $this->model;
        $ultimoError  = '';

        while ($intento < $this->maxRetries) {
            $resultado = $this->llamarModelo($modeloActual, $payload);

            if ($resultado['ok']) {
                return $resultado['data'];
            }

            $ultimoError = $resultado['errorMsg'];

            // Error permanente (400, 403, 404) → no reintentar, lanzar excepción.
            if (!$this->esErrorTransitorio($resultado['httpCode'])) {
                throw new RuntimeException("Gemini API error (HTTP {$resultado['httpCode']}): {$resultado['errorMsg']}");
            }

            $intento++;

            // Si agotamos los reintentos con el modelo principal, probar fallback.
            if ($intento >= $this->maxRetries && $modeloActual !== $this->fallbackModel) {
                error_log("[GeminiClient] Cambiando a modelo fallback: {$this->fallbackModel}");
                $modeloActual = $this->fallbackModel;
                $intento = 0;
                continue;
            }

            if ($intento >= $this->maxRetries) {
                throw new RuntimeException("Gemini API error (HTTP {$resultado['httpCode']}): {$ultimoError} tras {$this->maxRetries} reintentos.");
            }

            // Backoff exponencial con jitter aleatorio.
            $esperaBase = pow(2, $intento); // 2, 4, 8, 16... segundos
            $jitter     = random_int(0, 1000000) / 1000000;
            $espera     = min($esperaBase + $jitter, 60);

            error_log("[GeminiClient] Reintentando en " . round($espera, 2) . " segundos... (Intento {$intento}/{$this->maxRetries})");
            usleep((int)($espera * 1000000));
        }

        throw new RuntimeException("No se pudo obtener respuesta de Gemini tras varios intentos. Último error: {$ultimoError}");
    }

    /**
     * Realiza una llamada HTTP a un modelo específico.
     */
    private function llamarModelo(string $modelo, array $payload): array {
        $url = "{$this->baseUrl}/{$modelo}:generateContent?key={$this->apiKey}";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $respuesta = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['ok' => false, 'httpCode' => 0, 'errorMsg' => 'Error de conexión: ' . $curlError];
        }

        if ($httpCode === 200) {
            return ['ok' => true, 'data' => json_decode($respuesta, true)];
        }

        $data     = json_decode($respuesta, true);
        $errorMsg = $data['error']['message'] ?? 'Error desconocido';

        return ['ok' => false, 'httpCode' => $httpCode, 'errorMsg' => $errorMsg];
    }

    /**
     * Determina si un código de error es transitorio (merece reintento).
     */
    private function esErrorTransitorio(int $httpCode): bool {
        return in_array($httpCode, [429, 500, 502, 503, 504], true);
    }

    /**
     * Construye el payload de la petición.
     */
    private function construirPayload(array $contents, array $tools, array $systemInstruction): array {
        $payload = ['contents' => $contents];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
        }

        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = $systemInstruction;
        }

        $payload['generationConfig'] = [
            'temperature'     => 0.7,
            'topP'            => 0.95,
            'topK'            => 40,
            'maxOutputTokens' => 1024,
        ];

        return $payload;
    }
}