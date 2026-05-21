# BudgetLove

BudgetLove is a self-hosted household finance app for tracking, planning and controlling personal finances.

## Status

BudgetLove is currently in closed beta. Users can register, but accounts must be activated by an admin before login. Public self-service signup is intentionally not enabled for the 1.0 line.

## Core Ideas

- Your data stays in your own instance.
- Running BudgetLove on a VPS still means the database is yours, not a central BudgetLove cloud.
- PostgreSQL is the stable database target.
- SQLite is tracked as a future single-user option, but is not a 1.0 stable runtime yet.
- AI integrations use explicit API tokens and user-controlled endpoints.

## Quickstart

```bash
git clone <repo-url> budgetlove
cd budgetlove
cp .env.example .env
docker compose -f compose/docker-compose.yml -f compose/docker-compose.expose.yml -f compose/docker-compose.dev.yml up --build -d
```

Open `http://<server-ip>:8085/`.

For production setup, environment variables and reverse proxy notes, see `docs/guides/developer-setup.md`.

## Documentation

- `docs/README.md` - documentation index
- `docs/guides/developer-setup.md` - developer and deployment guide
- `docs/product/roadmap-1.0.md` - 1.0 roadmap and open launch tasks
- `docs/product/periods.md` - calendar, salary-day and actual-salary period logic
- `docs/api/ai-integrations.md` - Custom GPT, MCP and API integration guide
- `docs/api/openapi.yaml` - API documentation

## License

BudgetLove is prepared for AGPL-3.0-or-later licensing for the public 1.0 release.
