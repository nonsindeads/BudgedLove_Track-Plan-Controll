# BudgetLove Infra Split Decision

Stand: 2026-05-21

## Entscheidung

Nicht-App-Routen und Server-Infrastruktur werden dauerhaft ausserhalb des BudgetLove-App-Repositories gepflegt.

Betroffen sind insbesondere Routen und Services fuer:

- `cloud.*`
- `dns.*`
- `git.*`
- `code-*.*`
- `retrogaming.*`

## Zielort

Ziel ist ein separates Infrastruktur-Repository oder ein separater Deploy-Pfad ausserhalb dieses App-Repositories.

Arbeitsname:

```text
server-infra-bootstrap
```

Das BudgetLove-App-Repo enthaelt weiterhin nur:

- BudgetLove-App-Code
- App-spezifische Docker-/Compose-Dateien
- App-Migrationen
- App-Dokumentation
- App-spezifische API-/GPT-Dokumentation

## Regel

Vor jedem App-Commit mit Infrastrukturbezug pruefen:

```bash
git diff -- compose/
```

Falls dabei nicht-app-spezifische Caddy-/Proxy-/DNS-/Code-Server-/Retrogaming-Routen auftauchen, gehoeren diese nicht in dieses Repository.
