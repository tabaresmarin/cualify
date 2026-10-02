<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Bootstrap de la aplicacion: carga el .env, resuelve la configuracion y
 * construye el contenedor. Es la unica clase que debe instanciarse "a mano".
 */
final class App
{
    private static ?self $instance = null;

    /** @var array<string, mixed> */
    private array $config = [];

    /** @var array<string, object> */
    private array $container = [];

    private ?string $urlBase = null;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    public static function env(string $clave, mixed $porDefecto = null): mixed
    {
        $valor = getenv($clave);
        if ($valor !== false) {
            return $valor;
        }
        return $_ENV[$clave] ?? $porDefecto;
    }

    /**
     * Carga el archivo .env. Las variables ya presentes en el entorno real
     * tienen prioridad, igual que en el bot.
     */
    public static function loadEnv(string $ruta): void
    {
        if (!is_file($ruta)) {
            return;
        }

        foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);

            if ($linea === '' || $linea[0] === '#' || $linea[0] === ';') {
                continue;
            }

            $partes = explode('=', $linea, 2);
            if (count($partes) !== 2) {
                continue;
            }

            $clave = trim($partes[0]);
            $valor = trim($partes[1]);

            if (strlen($valor) >= 2) {
                $comillado = ($valor[0] === '"' && substr($valor, -1) === '"')
                    || ($valor[0] === "'" && substr($valor, -1) === "'");
                if ($comillado) {
                    $valor = substr($valor, 1, -1);
                }
            }

            if (getenv($clave) !== false) {
                continue;
            }

            putenv("$clave=$valor");
            $_ENV[$clave] = $valor;
        }
    }

    public function bootstrap(string $basePath): self
    {
        self::loadEnv($basePath . '/.env');

        $this->config = require $basePath . '/config/app.php';

        date_default_timezone_set($this->config['timezone']);

        if ($this->config['debug']) {
            error_reporting(E_ALL);
            ini_set('display_errors', '1');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', '0');
            ini_set('log_errors', '1');
        }

        $this->bind(Database::class, fn () => new Database(require $basePath . '/config/database.php'));
        $this->bind(Request::class, fn () => Request::capture());
        $this->bind(Response::class, fn () => new Response());
        $this->bind(Session::class, fn () => new Session($this->config['session']));
        $this->bind(\App\Models\User::class, fn ($app) => new \App\Models\User($app->make(Database::class)));
        $this->bind(\App\Models\Lead::class, fn ($app) => new \App\Models\Lead($app->make(Database::class)));
        $this->bind(Auth::class, fn ($app) => new Auth($app->make(\App\Models\User::class), $app->make(Session::class)));
        $this->bind(\App\Services\MetricsService::class, fn ($app) => new \App\Services\MetricsService($app->make(Database::class)));
        $this->bind(Router::class, fn () => new Router());

        return $this;
    }

    public function config(string $ruta, mixed $porDefecto = null): mixed
    {
        $valor = $this->config;

        foreach (explode('.', $ruta) as $segmento) {
            if (!is_array($valor) || !array_key_exists($segmento, $valor)) {
                return $porDefecto;
            }
            $valor = $valor[$segmento];
        }

        return $valor;
    }

    /** @return array<string, mixed> */
    public function configAll(): array
    {
        return $this->config;
    }

    public function bind(string $clave, \Closure $resolver): void
    {
        $this->container[$clave] = $resolver;
    }

    public function make(string $clave): mixed
    {
        if (isset($this->container[$clave])) {
            return ($this->container[$clave])($this);
        }

        if (class_exists($clave)) {
            return new $clave();
        }

        throw new \RuntimeException("No se pudo resolver el servicio: {$clave}");
    }

    public function viewPath(): string
    {
        return $this->config['view_path'];
    }

    public function basePath(string $sufijo = ''): string
    {
        return $this->config['base_path'] . ($sufijo !== '' ? '/' . ltrim($sufijo, '/') : '');
    }

    /**
     * URL base del panel, sin slash final.
     *
     * Se deriva de SCRIPT_NAME — que es la ruta que el servidor web realmente
     * mapeo a este archivo — en vez de confiar en APP_URL. Asi el panel funciona
     * igual en la raiz del dominio, en un subdirectorio anidado
     * (/cualify/cualify-dashboard/public) o detrás de un alias, sin tener que
     * acertar una variable de entorno. APP_URL solo se usa como fallback en CLI,
     * donde no existe SCRIPT_NAME.
     */
    public function url(string $ruta = ''): string
    {
        $this->urlBase ??= $this->detectarUrlBase();

        if ($ruta === '') {
            return $this->urlBase;
        }

        return $this->urlBase . '/' . ltrim($ruta, '/');
    }

    /** Prefijo de ruta del panel, por ejemplo /cualify/cualify-dashboard/public. */
    public function pathPrefix(): string
    {
        $prefijo = (string) (parse_url($this->url(), PHP_URL_PATH) ?: '');

        return $prefijo === '/' ? '' : rtrim($prefijo, '/');
    }

    private function detectarUrlBase(): string
    {
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));

        if ($script === '' || PHP_SAPI === 'cli') {
            return rtrim((string) ($this->config['url'] ?? ''), '/');
        }

        $prefijo = rtrim(dirname($script), '/');

        if ($prefijo === '.' || $prefijo === '/') {
            $prefijo = '';
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');

        if ($host === '') {
            // Sin Host (CLI con -S, por ejemplo): al menos conservamos el path.
            return rtrim((string) ($this->config['url'] ?? ''), '/') . $prefijo;
        }

        return $this->esHttps() ? 'https://' . $host . $prefijo : 'http://' . $host . $prefijo;
    }

    private function esHttps(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');

        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }
}
