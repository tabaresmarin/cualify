# AGENTS.md

## Project

WhatsApp Business lead qualification system (Spanish throughout). Receives Meta Cloud API webhook events, routes leads through a qualification conversation, scores their website with Google PageSpeed Insights, and books advisor calls into Google Calendar.

Two parallel entry points drive the same `leads` table:

1. **Conversational flow**: `webhook.php` → `procesarCalificacion()` in `lead_qualifier.php` (interactive reply-button / list messages).
2. **Native WhatsApp Flow "Consultar Tarifas"**: `flow_data_endpoint.php`, launched from a template button. Every request is encrypted (RSA-OAEP-SHA256 key wrap + AES-128-GCM body; the response uses the bit-flipped IV) — Meta requires this, it is not optional.

## Structure

- `webhook.php` — Meta webhook: GET verification, optional `X-Hub-Signature-256` check (only if `WA_APP_SECRET` is set), POST that processes **every** message in the payload, not just the first.
- `lead_qualifier.php` — conversation state machine (`procesarCalificacion($phone, $respuestaId, $textoLibre)`). Exposes `actualizarLead()` (whitelisted columns only) and `obtenerConexion()`, both reused by the Flow endpoint.
- `whatsapp_api.php` — senders. **Inconsistency:** the interactive/text senders return `['http_code','respuesta','error']`, but `enviarPlantillaWhatsApp()` returns the raw response body string (which `test_send.php` decrypts via `json_decode`).
- `flow_data_endpoint.php` — the real Flow endpoint (encrypted, needs phpseclib). `flow_endpoint.php` is a legacy plain-JSON mock with hardcoded slots and `rand()` PageSpeed — do not extend it.
- `appointment_slots.php` — slot logic shared by both flows: Mon–Fri only, `CITA_HORAS_FIJAS` (10:00 / 15:00), `America/Bogota`, next 5 business days from tomorrow, 60-min events. Conversational row ids are `slot_YYYY-MM-DD_HH:MM`; Flow ids are ISO-8601 with offset.
- `pagespeed_api.php` — PageSpeed Insights v5 (`PSI_API_KEY`, mobile/performance, 45 s timeout); thresholds ≤50 `muy_necesitado`, ≤70 `oportunidad_mejora`, else `listo_marketing`.
- `google_calendar_api.php` — service account + domain-wide delegation; JWT signed with `openssl_sign` (phpseclib not used here). Requires `GOOGLE_SERVICE_ACCOUNT_JSON`, `GOOGLE_CALENDAR_ID`, `GOOGLE_IMPERSONATE_EMAIL`.
- `config.php` — env loader. Hard-requires `WA_VERIFY_TOKEN`, `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, `DB_HOST`, `DB_USER`, `DB_PASS`, `DB_NAME` (500-exit if missing).
- `database.sql` — birth snapshot of the schema. `migration_renovacion_web.sql` added the qualification columns (`tiene_sitio_web`, `url_sitio`, `pagespeed_score`, `clasificacion`, `cita_*`, `google_event_id`). `database.php` is legacy/unused.

## Setup

1. `composer install` — phpseclib3, needed only by `flow_data_endpoint.php` (`vendor/` is present but untracked).
2. Copy `.env.example` → `.env`. The committed example is incomplete; the code also reads `PSI_API_KEY`, the three `GOOGLE_*` vars, and `FLOW_PRIVATE_KEY_PATH` / `FLOW_PRIVATE_KEY_PASSPHRASE` (note the `FLOW_` prefix — `.env` currently uses `PRIVATE_KEY_PATH`, which the code does not read). The setup guides live in the file headers of `pagespeed_api.php`, `google_calendar_api.php`, and `flow_data_endpoint.php`.
3. Schema: `mysql -u root -p db_cualify < database.sql && mysql -u root -p db_cualify < migration_renovacion_web.sql`.
4. In the WhatsApp Flows dashboard, point the data endpoint at `flow_data_endpoint.php` and upload the Flow's public key.

## Running

- `php test_send.php NUMERO` (or `?to=NUMERO`) sends the `consultar_tarifas` template (language `en`) with a Flow button; the template must be approved in Meta.
- No test suite, no build step, no lint/typecheck.

## Gotchas

- **Secrets sitting untracked in the webroot**: `private.pem` / `public.pem` (Flow RSA keys) and `caldav-333715-*.json` (Google service-account key). `.gitignore` only lists `.env` — never `git add` these, and consider moving them out of the document root.
- The Flow button must be sent with `flow_token` = the recipient's phone number — the endpoint's only way to identify the lead. A placeholder like `'unused'` breaks lead lookup / calendar booking. `test_send.php` already does this correctly.
- Flow endpoint responses are `text/plain` base64 ciphertext, not JSON. `flow_endpoint.php` returns plain JSON but is a mock.
- Steps (`leads.step`): 1 greeting → 2 service filter → 5 service submenu → 10 has-site → 11 site age → 12 waits for **free-text URL** (normalized, `https://` auto-prepended; invalid → re-ask, stays at 12) → 14 waits for a `slot_*` list reply → 99 terminal (silent, no reset). The Flow inserts new leads at step 0. Free text is only consumed at step 12.
- `leads.status` written by PHP is `en_calificacion` / `calificado` / `descartado`, but `database.sql` declares an English enum (`new`,`qualifying`,…). They only agree against the live, migrated DB — so always migrate additively and never re-import `database.sql` over an existing DB (it DROPs tables).
- HTTP 200 only means Meta **accepted** the message, not that it was delivered; the state machine advances `step`/`status` only on 200 so a failed send doesn't drift the conversation.
- Interactive messages (buttons/list/text) only deliver inside the 24 h window after the customer last wrote to the bot; outside it use `enviarPlantillaWhatsApp()` with an approved template.
- `leads.phone` is UNIQUE. `conversation_states` and `clients` exist in the schema but neither current flow uses them.