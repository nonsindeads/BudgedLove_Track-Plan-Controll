# Contributing

BudgetLove is in closed beta until the 1.0 release. Contributions should keep the app self-hosted, privacy-first and understandable for non-enterprise users.

## Development Rules

- Keep application code in this repository.
- Do not add unrelated infrastructure routes for other services to this repo.
- Do not commit secrets, tokens, production data or personal banking data.
- Run PHP lint for changed PHP files before committing.
- Update docs when behavior changes.

## Branches

- `release` is the active deployment branch.
- Feature work should be committed in small, reviewable commits.

## Database

PostgreSQL is the supported runtime database. SQLite is a planned compatibility target, but not stable for 1.0 yet.

