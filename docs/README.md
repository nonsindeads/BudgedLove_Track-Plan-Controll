# Household Book – Developer Guide (MVP)

## Quickstart
```bash
git clone <repo-url> /srv/haushaltsbuch/repo
cd /srv/haushaltsbuch/repo
docker compose -f compose/docker-compose.yml -f compose/docker-compose.expose.yml -f compose/docker-compose.dev.yml up --build -d
```

Open: `http://<server-ip>:8085/`
Local Docker data is stored in `./.data/` (git-ignored) to keep test data out of releases.

## Project Structure
- `public/` – PHP entry points/pages (Login/Register, Household Wizard, Accounts, Recurring, Plan, Open Cases, Period Close, Categories, Tags, Payees, Transactions, Attachments, History).
- `app/` – DB/domain helpers (`db.php` migration runner, `domain.php` household/plan/forecast/upload helpers), `migrations/*.sql`, `ws/`.
- `compose/` – Docker Compose + Nginx/PHP-FPM setup including WebSocket service.
- `docs/DOMAIN.md` – Domain model & tables.
- `docs/CRON.md` – Cron runner for recurring rules.
- `docs/ENV.md` – Environment variables.
- `docs/PERIODS.md` – Household period modes and salary-anchor setup.

## Quickstart (Docker)
```bash
cd /srv/haushaltsbuch/repo
docker compose -f compose/docker-compose.yml -f compose/docker-compose.expose.yml -f compose/docker-compose.dev.yml up --build -d

# Migrations are applied on first hb_get_pdo() call.
docker exec hb_app php -r "require '/var/www/app/db.php'; hb_get_pdo(); echo \"migrations ok\n\";"

# Check tables
docker exec hb_db psql -U hb_app -d haushaltsbuch -c "\dt"
docker exec hb_db psql -U hb_app -d haushaltsbuch -c "select * from migrations order by applied_at desc;"

# WebSocket service (live feed/chat) uses Workerman
docker logs hb_ws
```

## Default Logins
- Admin: `admin` / `admin` (already activated).
- New users register on `/register` and must be activated by an admin on `/` (Admin card).

## Key Routes / Features
- Household setup: `/household.php` (copies global categories/tags).
- Plan & recurring: `/recurring.php`, `/plan.php`.
- Open cases & period close: `/open_cases.php`, `/month_close.php`.
- CRUD: `/accounts.php`, `/categories.php`, `/tags.php`, `/payees.php`.
- Transactions: `/transactions.php` (transfers, splits, tags, attachments).
- Attachments: upload to `/srv/haushaltsbuch/uploads/<household_id>/…`, download via `/attachments.php`.
- History/Audit: `/history.php` (filters & diff).
- Dashboard uses the account filter (header select) for forecast/cards.
- Period calculation is configured per household under `/household.php?action=settings`.

## Cron
See `docs/CRON.md`. Example:
```
*/5 * * * * /usr/bin/php /srv/haushaltsbuch/repo/cron.php
```
Cron needs the same DB/upload env vars as the app.

## Environment Variables
See `docs/ENV.md` (HB_DB_DSN, HB_DB_USER, HB_DB_PASS, HB_UPLOAD_DIR, APP_BASE_URL, HB_WS_URL, HB_WS_SECRET, HB_WS_BIND).

## Local Setup (no Docker)
- PHP 8.3 + pdo_pgsql.
- Point the webserver at `public/`.
- Export `.env` variables or set them in the webserver (do not commit to git).
  - Production: clone repo, track `release`, update via `git pull origin release`.

## Production Setup & Deploy
1) Clone repo
```
git clone <repo-url> /srv/haushaltsbuch/repo
cd /srv/haushaltsbuch/repo
```

2) Set environment (e.g. `.env` or Docker Compose env)
- See `docs/ENV.md`

3) Start containers
```
docker compose -f compose/docker-compose.yml -f compose/docker-compose.prod.yml up --build -d
```

4) Update
```
cd /srv/haushaltsbuch/repo
git pull origin release
docker compose -f compose/docker-compose.yml -f compose/docker-compose.prod.yml up -d --build
```

Note: Production data stays in the DB volume; code updates do not delete existing data.

### Production Domains (Caddy + HTTPS)
The production stack uses Caddy for automatic HTTPS and reverse proxying.

- Root domain (`https://budgetlove.de`) serves the static landing page from `landing/`.
- App domain (`https://app.budgetlove.de`) proxies to the PHP app and WebSocket service.

Configure DNS:
- `A` / `AAAA` records for `budgetlove.de` → your server IP
- `A` / `AAAA` records for `app.budgetlove.de` → your server IP

Ensure ports 80 and 443 are open on the server. Caddy will obtain and renew certificates automatically.

### Central Proxy (Recommended)
If you already run a shared reverse proxy for multiple stacks (Dockge/GitLab/Pi-hole),
use the proxy overlay instead of the built-in Caddy stack.

See `docs/PROXY.md` for the full setup and sample Caddyfile.

## Release Migrations
Release migrations allow safe upgrades across multiple versions. They are applied automatically
on first request after deploy, in semantic version order.

**Structure**
- `VERSION` defines the app version (source of truth).
- Release migrations live in `app/migrations/releases/<version>/`.
- Each file uses numeric prefixes for ordering (e.g. `001_add_table.sql`).
- `.sql` files run via PDO; `.php` files must `return function(PDO $pdo) { ... };`.

**Runtime behavior**
- The app reads `VERSION`, compares it to the DB version, and applies all missing releases.
- Applied files are recorded in `release_migrations`.
- The DB version is stored in `release_versions`.

## KI-Integration

Token erzeugen: `Household -> Settings -> API Tokens`.

Endpunkte:
- `GET /api/meta.php`
- `POST /api/receipts.php`
- `POST /api/transactions.php`

Alle API-Endpunkte erwarten:
- Header `Authorization: Bearer <token>`

Beispiel-Prompt:
`Fotografiere diesen Kassenbon, lade ihn via POST /api/receipts hoch und buche ihn dann via POST /api/transactions in BudgetLove.`
