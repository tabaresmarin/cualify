<?php
require_once __DIR__ . '/config.php';

/**
 * Integración con Google Calendar API v3 usando una cuenta de servicio (Service Account)
 * con delegación de dominio (Domain-Wide Delegation), sin librerías externas.
 *
 * ==========================================================================
 * CONFIGURACIÓN (hazlo una sola vez, requiere ser administrador de Google Workspace):
 * ==========================================================================
 *
 * 1) Crear el proyecto y la cuenta de servicio:
 *    - Ve a https://console.cloud.google.com/ y crea/selecciona un proyecto.
 *    - Habilita la "Google Calendar API" (APIs & Services > Library).
 *    - Ve a APIs & Services > Credentials > Create Credentials > Service Account.
 *    - Dale un nombre (ej: "cualify-calendar-bot") y crea la cuenta.
 *    - Entra a la cuenta de servicio creada > pestaña "Keys" > Add Key > Create new key > JSON.
 *    - Se descargará un archivo .json. Guárdalo FUERA del webroot público, por ejemplo:
 *        /home/tuusuario/secrets/google-service-account.json
 *      (nunca lo subas a git; agrégalo a .gitignore)
 *
 * 2) Autorizar la delegación de dominio (esto es lo que evita que el usuario tenga que aprobar nada):
 *    - En la página de la cuenta de servicio, copia el "Client ID" (un número largo).
 *    - Entra como super-admin a https://admin.google.com
 *    - Seguridad > Control de acceso y datos > Delegación en todo el dominio (Domain-wide Delegation)
 *    - "Agregar nuevo": pega el Client ID y en Ámbitos (Scopes) agrega EXACTAMENTE:
 *        https://www.googleapis.com/auth/calendar
 *    - Guarda. Los cambios pueden tardar unos minutos en propagarse.
 *
 * 3) Compartir/preparar el calendario:
 *    - La delegación de dominio permite que la cuenta de servicio actúe COMO
 *      andres.tabares@eiso.com.co (impersonación), así que no hace falta
 *      compartir el calendario manualmente: ya tiene acceso a su propio calendario.
 *
 * 4) Variables de entorno (agrega esto a tu .env, ver .env.example):
 *      GOOGLE_SERVICE_ACCOUNT_JSON=/ruta/absoluta/secrets/google-service-account.json
 *      GOOGLE_CALENDAR_ID=andres.tabares@eiso.com.co
 *      GOOGLE_IMPERSONATE_EMAIL=andres.tabares@eiso.com.co
 *
 * ==========================================================================
 */

const GOOGLE_CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar';

/**
 * Codifica en base64url (sin padding), formato requerido por JWT.
 */
function base64UrlEncode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Genera un JWT firmado (RS256) y lo intercambia por un access_token de Google (OAuth2 Server-to-Server).
 * $subject: correo del usuario a impersonar (requiere Domain-Wide Delegation habilitada).
 * Devuelve el access_token (string) o null si falló.
 */
function obtenerTokenAccesoGoogle($scope, $subject = null) {
    $rutaJson = valorEntorno('GOOGLE_SERVICE_ACCOUNT_JSON');
    if (!$rutaJson || !is_file($rutaJson)) {
        error_log('[google_calendar_api] Falta o no existe GOOGLE_SERVICE_ACCOUNT_JSON: ' . $rutaJson);
        return null;
    }

    $cuentaServicio = json_decode(file_get_contents($rutaJson), true);
    if (!$cuentaServicio || empty($cuentaServicio['client_email']) || empty($cuentaServicio['private_key'])) {
        error_log('[google_calendar_api] El JSON de la cuenta de servicio es inválido.');
        return null;
    }

    $ahora = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));

    $claim = [
        'iss'   => $cuentaServicio['client_email'],
        'scope' => $scope,
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $ahora,
        'exp'   => $ahora + 3600,
    ];
    if ($subject) {
        $claim['sub'] = $subject; // Impersonación vía Domain-Wide Delegation
    }
    $payload = base64UrlEncode(json_encode($claim));

    $entradaFirma = $header . '.' . $payload;

    $firmaOk = openssl_sign($entradaFirma, $firma, $cuentaServicio['private_key'], 'sha256WithRSAEncryption');
    if (!$firmaOk) {
        error_log('[google_calendar_api] No se pudo firmar el JWT con la private_key de la cuenta de servicio.');
        return null;
    }

    $jwt = $entradaFirma . '.' . base64UrlEncode($firma);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $respuesta = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($respuesta, true);
    if ($httpCode !== 200 || empty($data['access_token'])) {
        error_log('[google_calendar_api] Error obteniendo access_token: ' . $respuesta);
        return null;
    }

    return $data['access_token'];
}

/**
 * Crea un evento en el Google Calendar configurado (GOOGLE_CALENDAR_ID).
 *
 * @param string   $resumen      Título del evento
 * @param string   $descripcion  Descripción/detalle del evento
 * @param DateTime $inicio       Fecha/hora de inicio (con timezone ya seteada)
 * @param DateTime $fin          Fecha/hora de fin (con timezone ya seteada)
 * @param string|null $attendeeEmail  Opcional: correo del lead para invitarlo (si se conoce)
 *
 * @return array ['ok' => bool, 'event_id' => string|null, 'html_link' => string|null, 'error' => string|null]
 */
function crearEventoCalendar($resumen, $descripcion, DateTime $inicio, DateTime $fin, $attendeeEmail = null) {
    $calendarId = valorEntorno('GOOGLE_CALENDAR_ID');
    $subject    = valorEntorno('GOOGLE_IMPERSONATE_EMAIL') ?: $calendarId;

    if (!$calendarId) {
        return ['ok' => false, 'event_id' => null, 'html_link' => null, 'error' => 'Falta configurar GOOGLE_CALENDAR_ID en .env'];
    }

    $token = obtenerTokenAccesoGoogle(GOOGLE_CALENDAR_SCOPE, $subject);
    if (!$token) {
        return ['ok' => false, 'event_id' => null, 'html_link' => null, 'error' => 'No se pudo autenticar contra Google Calendar (revisa la cuenta de servicio y la delegación de dominio).'];
    }

    $evento = [
        'summary'     => $resumen,
        'description' => $descripcion,
        'start' => ['dateTime' => $inicio->format(DateTime::RFC3339), 'timeZone' => 'America/Bogota'],
        'end'   => ['dateTime' => $fin->format(DateTime::RFC3339),    'timeZone' => 'America/Bogota'],
        'reminders' => ['useDefault' => true],
    ];

    if ($attendeeEmail) {
        $evento['attendees'] = [['email' => $attendeeEmail]];
    }

    $ch = curl_init('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($evento, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $respuesta = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($respuesta, true);

    if ($httpCode === 200 || $httpCode === 201) {
        return [
            'ok'        => true,
            'event_id'  => $data['id'] ?? null,
            'html_link' => $data['htmlLink'] ?? null,
            'error'     => null,
        ];
    }

    $mensajeError = $data['error']['message'] ?? ('HTTP ' . $httpCode);
    return ['ok' => false, 'event_id' => null, 'html_link' => null, 'error' => $mensajeError];
}
