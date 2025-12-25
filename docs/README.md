# Haushaltsbuch – Entwickler-Doku (MVP)

## Projektaufbau
- `public/` – PHP-Entry-Points/Seiten (Login/Register, Household-Wizard, Accounts, Categories, Tags, Payees, Transactions, Attachments).
- `app/` – DB/Domain-Helfer (`db.php` mit Migration-Runner, `domain.php` mit Household-/Parsing-/Upload-Utilities), `migrations/*.sql`.
- `compose/` – Docker Compose + Nginx/PHP-FPM Setup.
- `docs/DOMAIN.md` – Domänenmodell & Tabellen.
- `docs/CRON.md` – Cron-Runner für `recurring_rules`.
- `docs/ENV.md` – Wichtige Env Vars.

## Quickstart (Docker)
```bash
cd /srv/haushaltsbuch/repo
docker compose -f compose/docker-compose.yml up --build -d

# Migrationen werden beim ersten Aufruf von hb_get_pdo() ausgeführt.
docker exec hb_app php -r "require '/var/www/app/db.php'; hb_get_pdo(); echo \"migrations ok\n\";"

# Tabellen prüfen
docker exec hb_db psql -U hb_app -d haushaltsbuch -c "\dt"
docker exec hb_db psql -U hb_app -d haushaltsbuch -c "select * from migrations order by applied_at desc;"
```

## Default-Logins
- Admin: `admin` / `admin` (bereits freigeschaltet).
- Neue User registrieren sich auf `/register`, werden vom Admin auf `/` (Admin-Karte) freigeschaltet.

## Wichtige Pfade/Funktionen
- Haushalt wählen/erstellen: `/household.php` (kopiert globale Kategorien/Tags).
- CRUD: `/accounts.php`, `/categories.php`, `/tags.php`, `/payees.php`.
- Buchungen: `/transactions.php` (inkl. Transfers, Splits, Tags, Anhänge).
- Anhänge: Upload in `/srv/haushaltsbuch/uploads/<household_id>/…`, Download via `/attachments.php`.

## Cron
Siehe `docs/CRON.md`. Beispiel:
```
*/5 * * * * /usr/bin/php /srv/haushaltsbuch/repo/cron.php
```
Cron benötigt dieselben DB/Upload-Env-Variablen wie die App.

## Env Variablen
Siehe `docs/ENV.md` (HB_DB_DSN, HB_DB_USER, HB_DB_PASS, HB_UPLOAD_DIR, APP_BASE_URL).

## Lokal ohne Docker
- PHP 8.3 + pdo_pgsql.
- Webserver auf `public/` zeigen lassen.
- `.env`-Variablen exportieren oder im Webserver setzen.
