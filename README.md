# Cualify

Repositorio con dos aplicaciones PHP para **EISO** (agencia de desarrollo web y
marketing en Colombia):

1. **`/` (raíz del repo) — el bot.** Bot de ventas y calificación de leads para
   WhatsApp Business. Califica, cotiza, agenda citas y hace seguimiento
   automático 24/7.
2. **`cualify-dashboard/` — el panel.** Aplicación web interna, de solo
   lectura, para consultar los KPIs, leads, conversaciones y análisis de
   rendimiento que produce el bot. **Ver
   [`cualify-dashboard/README.md`](cualify-dashboard/README.md)** para
   instalación y despliegue detallados del panel.

Son aplicaciones independientes (cada una con su `composer.json` y `.env`) que
comparten **solo la base de datos** MariaDB.

Todo el código, los comentarios y la interfaz están en **español**.

---

## Funciones principales

### Bot de WhatsApp (raíz del repo)

- **Ingesta de leads por webhook**: `webhook.php` recibe mensajes, botones,
  respuestas de listas y Flows de Meta; `lead_inbound.php` recibe leads de Meta
  Lead Ads o formularios web (speed-to-lead, respuesta en < 30 s).
- **Calificación con IA**: `lead_qualifier.php` implementa la máquina de estados
  de la conversación y `ia_engine.php` un bucle de *function calling* con Gemini
  (máx. 5 rondas) y herramientas: `buscar_producto`, `cotizar_producto`,
  `agendar_cita`, `enviar_url`, `descartar_lead` y filtro rápido de keywords de
  empleo.
- **Cola de trabajos**: nada lento ocurre dentro del webhook. Los trabajos se
  encolan en `jobs_queue` y los procesan workers CLI:
  - `worker_ia.php` → `ia_message` (indica "escribiendo…", responde, marca el job).
  - `worker_pagespeed.php` → `pagespeed_analysis` (PageSpeed Insights + agenda).
  - `worker_reminders.php` → recordatorios de cita 24 h y 1 h antes (evita no-shows).
  - `worker_followup.php` → re-engagement de leads fríos a las 2 h y 24 h.
- **Cotización**: `catalog.php` y `quoter.php` buscan productos y calculan
  descuentos por volumen/fijo, impuestos y envío.
- **Agenda**: `appointment_slots.php` genera slots (lun–vie, 10:00 y 15:00
  `America/Bogota`) y `google_calendar_api.php` crea eventos vía service
  account + Domain-Wide Delegation, sin SDK.
- **Rendimiento web**: `pagespeed_api.php` (PSI v5 móvil + caché 24 h) y
  `crux_api.php` (score CrUX instantáneo mientras corre PSI).
- **Flows de WhatsApp**: `flow_data_endpoint.php` descifra el payload
  (RSA-OAEP/AES-128-GCM), persiste el lead y encola el análisis.
- **Seguridad y control**: límite de 5 jobs `ia_message` por teléfono por
  minuto, handoff a asesor humano y envío de plantillas aprobadas fuera de la
  ventana de 24 h.

### Panel `cualify-dashboard/`

- Dashboard con KPIs del embudo, leads por día, estado de la cola, citas
  próximas y jobs atascados.
- Listado de leads con búsqueda y filtros, ficha con su conversación.
- Historial de conversaciones y cache de análisis PageSpeed.
- API interna: `GET /api/metrics?dias=30` (con sesión) y `GET /api/health`
  (sin sesión).
- Módulos de administración de clientes y **outreach** (inicio de chat
  manual: Flow, plantilla HSM o encolado de IA).
- Escribe **solo** en las tablas de autenticación (`users`,
  `login_attempts`); sobre el resto es de solo lectura.

---

## Requisitos previos

| Requisito | Bot (raíz) | Panel (`cualify-dashboard/`) |
|---|---|---|
| PHP | 8.x con `pdo_mysql`, `openssl`, `curl`, `mbstring` | ≥ 8.2 con `pdo_mysql`, `mbstring`, `json` |
| Composer | Sí (`vendor/`: phpseclib; tcpdf declarado sin usar) | Sí (solo autoload PSR-4, sin dependencias de terceros) |
| Base de datos | MariaDB / MySQL con la base `cualify` | La misma base del bot |
| Cuenta Meta | WhatsApp Business Account + Cloud API + App de Flows | — |
| APIs externas | Gemini, Google Calendar (service account), PageSpeed Insights | — |
| Hosting | cPanel/LiteSpeed o cualquier PHP (SSH **no** requerido) | Igual; docroot debe ser `cualify-dashboard/public` |

Variables de entorno requeridas por el bot (si faltan, `config.php` aborta con
500): `WA_VERIFY_TOKEN`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, `DB_HOST`,
`DB_USER`, `DB_PASS`, `DB_NAME`, `GEMINI_API_KEY`, `GEMINI_MODEL`,
`GEMINI_FALLBACK_MODEL`, `PSI_API_KEY`, `GOOGLE_*`, `FLOW_PRIVATE_KEY_*`,
`WORKER_TRIGGER_TOKEN`, entre otras. Consulta `grep valorEntorno('...')` para
la lista completa; `.env.example` documenta la mayoría.

---

## Instalación

### 1. Bot (raíz del repo)

```bash
git clone https://github.com/tabaresmarin/cualify.git
cd cualify

# Dependencias (phpseclib para el descifrado de Flows)
composer install

# Configuración
cp .env.example .env
$EDITOR .env          # credenciales de DB, tokens de Meta, Gemini, etc.

# Base de datos: NO uses database.sql (es un bootstrap obsoleto).
# Usa el dump de producción funciona.sql o extrae el esquema vivo.
mysql -h 127.0.0.1 -u USUARIO -p cualify < funciona.sql
```

Verificación (no hay tests ni linter, solo validación de sintaxis):

```bash
find . -name '*.php' -not -path '*/vendor/*' -exec php -l {} \;
```

### 2. Panel (`cualify-dashboard/`)

```bash
cd cualify-dashboard

composer install                    # autoload PSR-4
cp .env.example .env                # APP_DEBUG y credenciales de DB
$EDITOR .env

# Tablas de autenticación (una sola vez)
mariadb -h 127.0.0.1 -u USUARIO -p cualify < database/migrations/001_create_auth_tables.sql

# Primer usuario
php bin/crear_usuario.php "Nombre Apellido" "correo@eiso.com.co" "contrasena" admin
```

Detalle completo en [`cualify-dashboard/README.md`](cualify-dashboard/README.md).

---

## Uso básico

### Arranque local

```bash
# Bot: probar Gemini con un mensaje fijo
php test_gemini.php

# Bot: enviar la plantilla del Flow a un número
php test_send.php 573001234567

# Workers (CLI) — procesan UN job por ejecución
php worker_ia.php
php worker_pagespeed.php
php worker_reminders.php
php worker_followup.php

# Panel
cd cualify-dashboard
APP_URL=http://localhost:8000 php -S 127.0.0.1:8000 -t public
```

### Producción (cPanel sin acceso a shell)

Los cron se disparan por **HTTP**, porque el hosting puede tener SSH/Jailshell
deshabilitado. `cron_runner.php` ejecuta el worker en PHP puro, sin `exec()` ni
binarios de consola:

```bash
# Cada minuto — IA
/usr/bin/curl -s "https://eiso.com.co/cualify/cron_runner.php?job=ia&token=TU_WORKER_TRIGGER_TOKEN" >/dev/null 2>&1

# Cada minuto — PageSpeed
/usr/bin/curl -s "https://eiso.com.co/cualify/cron_runner.php?job=pagespeed&token=TU_WORKER_TRIGGER_TOKEN" >/dev/null 2>&1

# Cada 15 min — recordatorios de cita
/usr/bin/curl -s "https://eiso.com.co/cualify/cron_runner.php?job=reminders&token=TU_WORKER_TRIGGER_TOKEN" >/dev/null 2>&1

# Cada hora — seguimiento a leads fríos
/usr/bin/curl -s "https://eiso.com.co/cualify/cron_runner.php?job=followup&token=TU_WORKER_TRIGGER_TOKEN" >/dev/null 2>&1
```

También sirve un cron externo (cron-job.org) apuntando a esas mismas URLs, y
existe `run_worker.sh` como respaldo cuando sí hay shell.

### Endpoints principales

| Endpoint | Método | Uso |
|---|---|---|
| `/webhook.php` | POST/GET | Webhooks de Meta (mensajes, Flows, verificación) |
| `/lead_inbound.php` | POST | Ingesta de leads externos (Lead Ads / formularios) |
| `/flow_data_endpoint.php` | POST | Data endpoint de WhatsApp Flows (cifrado) |
| `/trigger_worker.php` | GET | Dispara un worker (`?worker=ia&token=…`) |
| `/cron_runner.php` | GET | Cron por HTTP (`?job=ia&token=…`) |
| `/dashboard`, `/leads`, `/conversations`, `/pagespeed`, `/clients`, `/outreach` | GET | Pantallas del panel (requieren sesión) |

---

## Estructura del repositorio

```
cualify/
├── webhook.php               # webhook de Meta → encola jobs (200 inmediato)
├── lead_inbound.php          # speed-to-lead (Meta Lead Ads / formularios)
├── flow_data_endpoint.php    # descifra y persiste leads de Flows
├── lead_qualifier.php        # máquina de estados + encoladores + rate limit
├── ia_engine.php             # bucle function-calling de Gemini + tools
├── gemini_api.php            # cliente Gemini con reintentos y fallback
├── worker_ia.php             # worker: mensajes de IA
├── worker_pagespeed.php      # worker: análisis PSI + calendario
├── worker_reminders.php      # worker: recordatorios 24 h / 1 h
├── worker_followup.php       # worker: re-engagement 2 h / 24 h
├── cron_runner.php           # dispara workers vía HTTP (sin shell)
├── trigger_worker.php        # shim HTTP que lanza workers en background
├── catalog.php / quoter.php  # búsqueda y cotización de productos
├── appointment_slots.php     # generación de slots de cita
├── google_calendar_api.php   # Google Calendar sin SDK
├── pagespeed_api.php         # PSI v5 + caché 24 h
├── crux_api.php              # score CrUX instantáneo
├── whatsapp_api.php          # envíos por Meta Cloud API
├── config.php / database.php # .env loader y PDO en caché
├── database.sql              # ⚠️ obsoleto — no usar para montar el esquema
├── AGENTS.md                 # reglas y contexto del proyecto para agentes
└── cualify-dashboard/        # panel interno (aplicación independiente)
    ├── app/                  # Core, Controllers, Models, Views, Services
    ├── config/               # app.php, database.php (leen el .env)
    ├── routes/web.php        # rutas del panel
    ├── bin/                  # crear_usuario.php
    ├── database/migrations/  # tablas de autenticación
    ├── public/               # docroot del panel
    └── README.md             # guía detallada del panel
```

---

## Despliegue

- **Bot**: subir la raíz al docroot del sitio (`public_html/cualify/`) por FTP.
  Los cron se configuran por HTTP (ver *Uso básico*), no se necesita shell.
- **Panel**: el docroot **debe** ser `cualify-dashboard/public`, nunca la raíz
  del repo (el `.htaccess` de seguridad bloquea `app/`, `config/` y `vendor/`).
  En producción: `APP_ENV=production`, `APP_DEBUG=false`.

---

## Advertencias importantes

- **`database.sql` no es el esquema actual.** La fuente de verdad es el dump
  de producción `funciona.sql` (no versionado). `leads.status` real es
  `ENUM('nuevo','en_calificacion','calificado','descartado')`.
- **Secretos en el docroot**: `.env`, `private.pem` y los JSON de Google
  viven dentro del webroot (están en `.gitignore`, pero el webserver puede
  servirlos). No agregar secretos nuevos ahí.
- **Nunca re-ejecutar `apply_flow_fix.php`**: es un codemod de una sola vez
  que hoy corrompería `flow.json`.
- **`trigger_worker.php` apunta a rutas de producción** y
  `dispararWorker()` usa `https://eiso.com.co/...`. En local, ejecuta los
  workers directamente con `php worker_ia.php`.
- Sin suite de tests ni CI: la única verificación automática es `php -l`.

---

## Licencia

Proyecto interno de EISO. Todos los derechos reservados.
