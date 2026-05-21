# BudgetLove — Claude Quick-Ref

Primary policy lives in `AGENTS.md` (UI conventions, stack constraints, versioning). This file is for fast lookup.

## Where things are
- App core: `app/{bootstrap,db,dbal,domain,oauth,security,cloud_sqlite}.php`, `app/ws/`
- Pages: `public/*.php` (one entry per route)
- AI API: `public/api/{receipts,transactions,meta}.php` (token-auth)
- Migrations: `app/migrations/releases/<version>/NNN_*.{sql,php}`
- Compose: `compose/docker-compose.{yml,dev,prod,proxy,caddy,expose}.yml`
- Docs: `docs/{README,ROADMAP_1_0,PERIODS,AI_INTEGRATIONS,DOMAIN,CRON,ENV}.md`
- Landing: `landing/` (DE + `landing/en/`)
- Version: `VERSION` (currently 0.30.x)
- Branch: `release`

## Common commands
```bash
# Dev stack up
docker compose -f compose/docker-compose.yml -f compose/docker-compose.expose.yml -f compose/docker-compose.dev.yml up --build -d

# Force-run migrations (auto on first request)
docker exec hb_app php -r "require '/var/www/app/db.php'; hb_get_pdo(); echo \"ok\n\";"

# DB inspection
docker exec hb_db psql -U hb_app -d haushaltsbuch -c "\dt"
docker exec hb_db psql -U hb_app -d haushaltsbuch -c "select * from migrations order by applied_at desc;"

# WebSocket logs (Workerman)
docker logs hb_ws
```

## Add a feature
1. Bump `VERSION` (patch per commit, minor per feature).
2. If schema change → `app/migrations/releases/<new-version>/NNN_*.sql` (or `.php` returning `function(PDO $pdo) { ... };`).
3. New page → `public/<route>.php`, server-side rendered, Bootstrap 5 + HTMX. Create/Edit → Whitebox-Modal.
4. New API endpoint → `public/api/`, token-auth, update `docs/api/openapi.yaml`.
5. Cloud-SQLite-Mode: prüfe ob Code im SQLite-Pfad funktioniert (anders als Postgres bei Upserts/Audit-notify).

## Don't
- Don't add React/Vue/bundlers/build tools.
- Don't drop non-app Caddy routes (cloud./dns./git./code-*./retrogaming.) back into `compose/` — they belong outside this repo.
- Don't remove features without explicit ask.
- Don't `--amend` published commits; create a new commit instead.

## Outstanding
- Caddy-Split target still undecided — surface at next infra touch.
- Many local commits ahead of `origin/release` — push requires SSH or HTTPS token.
