# AGENTS.md

## Project

WhatsApp Business lead qualification bot. PHP webhook server that receives Meta Cloud API webhook events and routes leads through a qualification flow via interactive buttons.

Language throughout the codebase is Spanish.

## Structure

- `webhook.php` — entrypoint. Handles Meta webhook verification (GET), optional `X-Hub-Signature-256` validation (only if `WA_APP_SECRET` is set), and incoming messages (POST). Processes **every** message in the payload, not just the first.
- `lead_qualifier.php` — conversation state machine driven by button replies (single entrypoint `procesarCalificacion`). State lives in `leads.step` / `leads.status`.
- `whatsapp_api.php` — sends interactive/text/template messages via Meta Cloud API (cURL). All senders return `['http_code', 'respuesta', 'error']`.
- `config.php` — loads credentials from env / `.env`; **never put secrets here**.
- `database.sql` — schema (MariaDB dump); import before first run.
- `database.php` — legacy PDO helpers, **not used** by the current flow (the flow uses mysqli directly in `lead_qualifier.php`).

## Setup

1. Copy `.env.example` → `.env` and fill in the values.
2. Import DB: `mysql -u root -p db_cualify < database.sql`.
3. Serve project root so `webhook.php` is reachable, then point the Meta webhook at it.

## Running

- Test template send: `php test_send.php NUMERO` (e.g. `573001234567`); also works via `?to=NUMERO`. No arg → usage.
- No build step, no dependency manager, no test suite.

## Gotchas

- All secrets live in `.env` (gitignored). `.env.example` is the committed template. Server env vars take precedence over `.env`.
- The flow is driven by `leads.step` (1 bienvenida → 2 selección → 3 urgencia → 4 calificado / 99 cerrado) and `leads.status` enum (`nuevo`, `en_calificacion`, `calificado`, `descartado`). New leads have no row → inserted at step 1 with status `en_calificacion`.
- `whatsapp_api.php` senders return `['http_code', 'respuesta', 'error']`, not the raw body — check `resultado['http_code']`. HTTP 200 only means Meta **accepted** the message, not that it was delivered. `lead_qualifier.php` only advances `step`/`status` when the send returns 200, so a failed send does not drift the conversation forward.
- Free-form messages (buttons/text) are only delivered inside the 24 h window after the customer last wrote to the bot. Outside that window use `enviarPlantillaWhatsApp()` with an approved template.
- Meta webhook POSTs can be signature-validated by setting `WA_APP_SECRET` (HMAC-SHA256 via `X-Hub-Signature-256`); the check is skipped if the variable is absent.
- `leads.phone` is `UNIQUE`. `conversation_states` and `clients` exist in the schema but the current flow does not use them.
- Closed leads (step 4/99) currently receive no reply and there is no reset path; a free-text message at step 3 is treated as "no contratar" and marks the lead `descartado`.