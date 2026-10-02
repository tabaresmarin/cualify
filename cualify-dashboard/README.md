# Panel Cualify

Panel web interno para consultar lo que produce el bot de calificación de leads
de EISO. Vive dentro del repo del bot, pero es una aplicación independiente: no
comparte código con `webhook.php` ni con los workers, solo **la misma base de
datos**.

Es de solo lectura sobre los datos del bot. Lo único que escribe son las tablas
de autenticación (`users`, `login_attempts`).

## Qué muestra

| Pantalla | Ruta | Contenido |
|---|---|---|
| Dashboard | `/dashboard` | KPIs del embudo, leads por día, estado de la cola del bot, citas próximas, jobs atascados |
| Leads | `/leads` | Listado con búsqueda libre y filtros por estado/clasificación, ficha del lead con su conversación |
| Conversaciones | `/conversations` | Historial de `conversation_history` por teléfono |
| Rendimiento | `/pagespeed` | Cache de análisis PSI: distribución por categoría y últimos resultados |

API interna: `GET /api/metrics?dias=30` (requiere sesión) y
`GET /api/health` (sin sesión, para monitoreo).

## Requisitos

- PHP 8.2 o superior con `pdo_mysql`, `mbstring` y `json`
- La base de datos `cualify` ya existente (la del bot)
- Composer, solo para el autoload PSR-4 (el panel no tiene dependencias de terceros)

## Instalación

```bash
cd cualify-dashboard

# 1. Autoload (única dependencia: el propio autoloader)
composer install

# 2. Configuración
cp .env.example .env
$EDITOR .env          # APP_DEBUG y las credenciales de DB (APP_URL es opcional)

# 3. Tablas de autenticación (una sola vez)
mariadb -h 127.0.0.1 -u USUARIO -p db_cualify < database/migrations/001_create_auth_tables.sql

# 4. Primer usuario
php bin/crear_usuario.php "Nombre Apellido" "correo@eiso.com.co" "contrasena" admin
```

`bin/crear_usuario.php` también sirve para restablecer una contraseña o
reactivar una cuenta: si el correo ya existe, lo actualiza en lugar de fallar.

### Desarrollo local

```bash
APP_URL=http://localhost:8000 php -S 127.0.0.1:8000 -t public
```

## Despliegue en hosting compartido (cPanel/LiteSpeed)

El **docroot debe ser `cualify-dashboard/public`**, nunca la raíz del repo:

- En cPanel: * Domains → eiso.com.co → Document Root* →
  `/home/muuk9x7m9to5/public_html/cualify-dashboard/public`.
- Si no puedes cambiar el docroot, usa un subdominio o un alias. No intentes
  servir el panel con el docroot en la raíz del bot: el `.htaccess` de la raíz
  del subproyecto bloquea todo por seguridad, justamente para que `app/`,
  `config/` y `vendor/` nunca queden expuestos.

En producción define `APP_ENV=production` y `APP_DEBUG=false`.

**No hace falta configurar `APP_URL`.** `App::url()` autodetecta esquema, host y
prefijo real a partir de `SCRIPT_NAME`, de modo que el mismo código funciona en
raíz, subdirectorio o subdominio sin tocar el `.htaccess`. `APP_URL` solo hace
falta si mueves el panel por CLI (`bin/crear_usuario.php`) o si prefieres fijar
el prefijo a mano.

Si sirves el panel desde un subdirectorio **sin** usar el `.htaccess incluido
(por ejemplo con el servidor embebido de PHP), sí hay que pasar el prefijo:
`APP_URL=http://localhost:8000 php -S 127.0.0.1:8000 -t public`.

### Tablas opcionales

El panel lee `conversation_history` y `pagespeed_cache`, que no existen en
instalaciones antiguas del bot. Sus consultas pasan por
`Database::fetchValueSiExiste()` / `fetchAllSiExiste()`: si la tabla no está,
la pantalla se degrada a un estado vacío en vez de romperse con un 500. Lo mismo
ocurre con `MetricsService::etiquetasStatus()`, que reconoce tanto los estados
que escribe el bot (`new`, `requiere_humano`) como los del enum local
(`nuevo`, `descartado`).

## Estructura

```
app/
  bootstrap.php        carga config, sesión, rutas y manejador de errores
  Core/                App, Router, Request, Response, Database, View, Session, Auth, Model
  Controllers/         Auth, Dashboard, Leads, Conversations, Pagespeed
  Controllers/Api/     MetricsApi
  Middleware/          AuthMiddleware
  Models/              User (escritura), Lead (solo lectura)
  Services/            MetricsService — toda la lógica de KPIs
  Views/               layouts, partials y las 4 pantallas
config/                app.php, database.php (leen el .env)
routes/web.php         definición de rutas
bin/                   scripts CLI
database/migrations/   001_create_auth_tables.sql, 002_align_users_to_schema.sql
public/                index.php, .htaccess, assets
```

## Schema de referencia

La fuente de verdad es el dump de producción (`funciona.sql`), no
`database.sql` — ese último es el bootstrap original de 2026-09-16 y su
`leads.status` (`new/qualifying/...`) ya no existe. Tablas que usa este panel:

| Tabla | Notas |
|---|---|
| `leads` | `status` es ENUM(`nuevo`,`en_calificacion`,`calificado`,`descartado`) |
| `conversation_history` | `role` es ENUM(`user`,`model`) |
| `jobs_queue` | `status` es ENUM(`pending`,`processing`,`done`,`failed`) |
| `pagespeed_cache` | clave única `(url_hash, strategy)`, caduca a 24 h |
| `users` | `password` (no `password_hash`), `last_login` (no `last_login_at`), **no** hay `is_active`, `role` es ENUM(`admin`,`viewer`) |
| `login_attempts` | **no** está en el dump: es tabla propia del panel, creada por la migración 001 |

Consecuencias que conviene no deshacer:

- El handoff a un asesor se guarda como `status = 'descartado'`, porque el enum
  no tiene un estado propio para eso. Por eso la tarjeta "Requieren asesor" del
  dashboard cuenta descartados.
- El panel nunca consulta `users.is_active` ni escribe en `leads`.
- `login_attempts` es opcional en tiempo de ejecución: si la tabla no está,
  `User::tieneLoginAttempts()` lo detecta y el login sigue funcionando, solo
  que sin límite de intentos.

## Notas de mantenimiento

- Si el bot agrega columnas a `leads`, actualiza las listas de columnas de
  `app/Models/Lead.php` y los agregados de `app/Services/MetricsService.php`.
- `users.role` se guarda pero **no se aplica**: `admin` y `viewer` ven lo mismo.
  Si quieres diferenciar permisos, empieza por el middleware de autenticación.
- No hay suite de tests. La única verificación automática disponible es la
  sintaxis:

  ```bash
  find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
  ```

- El panel es síncrono a propósito (es una página de consulta), pero no cambies
  las agregaciones a consultas pesadas sobre tablas grandes: todo corre en el
  proceso de PHP con límites de tiempo del hosting.
