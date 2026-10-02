# AGENTS.md

## Project

WhatsApp Business sales bot for **EISO** (Colombian web-dev / marketing agency). Plain PHP, no framework, one concern per file in the webroot, MariaDB. Deployed on shared hosting (cPanel/LiteSpeed) at `https://eiso.com.co/cualify/`.

Everything is in **Spanish**: identifiers, comments, log messages, user-facing copy. Keep it that way.

## Architecture — async, queue-driven

**Nothing slow happens inside a webhook request.** Meta's webhooks have hard timeouts (~10 s Flows, ~20–30 s messages), so every slow call is deferred to a DB queue processed by CLI workers.

```
Meta POST webhook.php ──► jobs_queue (job_type)  ──► worker (cron, 1 job/run)
                                                 ├── 'ia_message'        → worker_ia.php       → Gemini → WhatsApp
                                                 └── 'pagespeed_analysis'→ worker_pagespeed.php→ PSI + Calendar

Meta POST flow_data_endpoint.php (encrypted Flow) ──► persists lead ──► enqueues 'pagespeed_analysis'
```

- `webhook.php` — enqueues and returns `200 OK` immediately. Text, `button_reply`, `list_reply` (slot) become `ia_message` jobs. `nfm_reply` (Flow completion) is **ignored** — the Flow's own data endpoint already enqueued the work. Then calls `dispararWorker()` so cron isn't the only trigger.
- `worker_ia.php` — CLI-only. Processes one `ia_message` job: shows the typing indicator, resolves the reply **as text**, sends it, marks the job `done` / `pending` (retry) / `failed` (attempts ≥ `max_attempts`).
- `worker_pagespeed.php` — CLI-only. Processes one `pagespeed_analysis` job via `procesarLeadFlowAsincrono()`.
- `trigger_worker.php` + `dispararWorker()` — HTTP shim that `nice`s a worker into the background so replies feel instant without waiting on the cron tick.
- `flow_data_endpoint.php` — WhatsApp Flow data endpoint. RSA-OAEP/AES-128-GCM decrypt (phpseclib, with an `openssl_*` fast path), saves lead data, enqueues, replies `SUCCESS` **before** any slow work. `flow_token` **must** be the recipient phone number — it's the only identifier tying a Flow session to a lead.

### Business logic files

| File | Role |
|---|---|
| `lead_qualifier.php` | Conversation state machine + job enqueuers + `dispararWorker()` + rate limit + human handoff. Requires all the API modules. |
| `ia_engine.php` | Gemini function-calling loop (max 5 rounds) + tool executors (`buscar_producto`, `cotizar_producto`, `agendar_cita`, `enviar_url`). `respuesta_directa` short-circuits the loop and skips a second Gemini call. |
| `gemini_api.php` | `GeminiClient`: retries w/ exponential backoff + jitter, falls back `GEMINI_MODEL` → `GEMINI_FALLBACK_MODEL` on transient errors only (429/5xx). |
| `pagespeed_api.php` | PSI v5 (mobile) + 24 h `pagespeed_cache` + score→message classification. |
| `crux_api.php` | Instant CrUX (real-user) score derived from LCP/INP/CLS thresholds — the "fast answer" while PSI runs. |
| `appointment_slots.php` | Slot generation. Mon–Fri only, 10:00 & 15:00 `America/Bogota`, 60 min. Two id formats: `slot_YYYY-MM-DD_HH:MM` (WhatsApp list) and ISO-8601 (Flow). |
| `google_calendar_api.php` | Service-account JWT + Domain-Wide Delegation, no SDK. |
| `catalog.php` / `quoter.php` | Product search (LIKE) and quoting (volume/fixed discounts, tax, shipping). |
| `whatsapp_api.php` | Meta Cloud API senders. |
| `config.php` | `.env` loader + `valorEntorno()`; defines the required `WA_*`/`DB_*` constants. |

`database.php` is **not** legacy — `obtenerConexion()` (cached PDO) is used everywhere. All DB access is **PDO**, never mysqli. `reconectarSiEsNecesario()` must be called after any long external call (PageSpeed, Calendar) or MySQL's `wait_timeout` kills the connection mid-job.

## Commands

No build, no linter, no test suite, no CI.

```bash
# Syntax check (the only automated check that exists)
php -l lead_qualifier.php
for f in *.php; do php -l "$f"; done

# Scan for duplicate function definitions across files (run after moving code)
php diagnostico_funciones.php        # self-described as temporary — delete after use

composer install                     # only needed for vendor/ (phpseclib; tcpdf is unused)

php worker_ia.php                    # drain one ia_message job (CLI only)
php worker_pagespeed.php             # drain one pagespeed_analysis job (CLI only)
php cleanup_cache.php                # delete expired pagespeed_cache rows (CLI only)

php test_send.php 573001234567       # or ?to=NUMERO — sends the Flow template
php test_gemini.php                  # smoke-test Gemini with a canned message
php debug_clasificar.php             # reflect clasificarPorRendimiento() and dump a sample
```

Cron runs both workers every minute in production.

## ⚠️ `database.sql` is NOT the current schema

`database.sql` is the original 2026-09-16 bootstrap and is **wrong**. The authoritative structure is the production dump (`funciona.sql`): 10 tables — `clients`, `conversation_history`, `conversation_states`, `jobs_queue`, `leads`, `pagespeed_cache`, `products`, `quotes`, `quote_rules`, `users`.

`database.sql` defines only `clients`, `leads`, `conversation_states` and its `leads.status` enum is `('new','qualifying','qualified','disqualified','scheduled')`. None of those values exist in the real enum.

**Do not bootstrap a fresh environment from `database.sql`.** Dump the live DB (or `funciona.sql`) instead. If you add a schema change, add a new `.sql` migration file.

### The enums that actually bite

`leads.status` is `('nuevo','en_calificacion','calificado','descartado')`. Writing `new` or `requiere_humano` throws at the enum — those were values the old bootstrap used, and both were removed from the code. The human-handoff path (`lead_qualifier.php`, "COMANDO ASESOR") now writes `descartado`, because the enum has no dedicated state for it.

`leads` also has **no** `client_id` or `meta_lead_id` column. `users` uses `password` and `last_login` (not `password_hash` / `last_login_at`), has **no** `is_active`, and its `role` enum is `('admin','viewer')` — English, not `visor`.

Before writing SQL, check the column actually exists in `funciona.sql`. `SELECT *` hides mistakes that a named column list would have caught.

From a shell, PDO may fail with `Access denied ... (unix_socket)` because the DB user authenticates via socket as `apache`. Connect over TCP (`127.0.0.1`) instead, which is what `cualify-dashboard/.env` does.

## Gotchas

- **Never re-run `apply_flow_fix.php`.** It is a one-shot codemod whose changes are already applied, and it targets an older mysqli-era `lead_qualifier.php`. It rewrites `flow.json` *before* it validates the `lead_qualifier.php` insertion marker, so running it today silently corrupts `flow.json` and then throws.
- **Worker paths are hardcoded to production.** `trigger_worker.php` points at `/home/muuk9x7m9to5/public_html/cualify/*.php` and `/usr/bin/php`; `dispararWorker()` hardcodes `https://eiso.com.co/cualify/trigger_worker.php`. Nothing dispatches background work outside that host — drive `worker_*.php` directly for local testing.
- **Token env vars are inconsistent.** `trigger_worker.php` and `dispararWorker()` use `WORKER_TRIGGER_TOKEN`; `worker_pagespeed.php:28` checks `WORKER_SECRET_TOKEN`, which is not in `.env`. So `worker_pagespeed.php` is effectively CLI-only over HTTP.
- **`.gitignore` is currently truncated** to just `.env` (uncommitted). `vendor/`, `private.pem`, `public.pem`, `caldav-*.json`, `worker*.log`, and `error_log` are exposed to git and, worse, `private.pem` / the CalDAV service-account JSON sit **inside the public webroot**. Restore the ignore list and move secrets out of the docroot.
- **`.user.ini` sets `display_errors = On`**, overriding the `ini_set('display_errors','0')` in `webhook.php`. PHP notices get printed into webhook responses that Meta expects to be clean.
- **`enviarPlantillaWhatsApp()` returns the raw response body**; every other sender in `whatsapp_api.php` returns `['http_code','respuesta','error']`. Check `resultado['http_code']` — 200 only means Meta *accepted* the message, not that it was delivered. Graph API version is `v19.0` for sends, `v21.0` for the typing indicator.
- **`webhook.php` does not validate `X-Hub-Signature-256`**, even though `config.php` still defines `WA_APP_SECRET` and documents that it does. The old AGENTS.md claim about signature validation was wrong.
- **`flow.json` is internally inconsistent:** `routing_model` routes to a screen `SUCCESS` that doesn't exist (`screens` defines `CONFIRMACION`), while `flow_data_endpoint.php` replies with `screen => 'SUCCESS'`. Also the `WEBSITE_URL` payload references `${form.name}` etc. but that screen has no `Form`-type element wrapping its inputs. Verify against the Flow Builder before debugging why the Flow stalls.
- **Free-form messages only reach a lead inside the 24 h window** after they last wrote. Outside it you must use `enviarPlantillaWhatsApp()` with an approved template.
- **Rate limit:** `puedeUsarIA()` allows 5 `ia_message` jobs per phone per minute.
- **Functions are duplicated across files on purpose** and many are wrapped in `if (!function_exists(...))`, because `webhook.php` / workers `require_once` overlapping sets. Run `diagnostico_funciones.php` before renaming or moving any function; the failure mode is a fatal redeclare at request time.
- **`lead_qualifier.php` still carries dead legacy wrappers** (`procesarMensajeEntrante`, `procesarSeleccionSlot`, `enviarListaSlots`) that bypass the queue and send inline. Don't call them from the webhook path.
- Conversation history is capped at 20 messages per phone and **wiped entirely** after a quote is sent (`limpiarHistorial()`), so the next message starts contextually fresh.
- `ia_engine.php` hardcodes `clientId = 1` (single-tenant) — revisit before multi-client.