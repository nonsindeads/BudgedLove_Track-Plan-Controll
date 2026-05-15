# BudgetLove Public GPT API — Soll/Ist + TODO

Stand: 2026-05-15  
Basis: Code-Analyse + E2E-Lauf gegen `https://app.budgetlove.de/api/*`

## E2E-Test (heute)

- `GET /api/meta.php` mit Bearer-Token: `200 OK`
- `POST /api/planned_payments.php`: `201 Created`
- Idempotency-Retry auf `POST /api/planned_payments.php` mit gleichem `Idempotency-Key`: gleiche Ressource-ID (`id=95`)
- `PATCH /api/planned_payments.php?id=95`: `200 OK`
- `DELETE /api/planned_payments.php?id=95`: `200 OK`
- Idempotency-Retry auf `DELETE`: `200 OK`, gleiche fachliche Antwort
- Temporärer Test-Token wurde danach widerrufen

Gefundener und behobener Defekt:
- `planned_payments` Write schlug bei `is_optional` mit DB-Fehler fehl (Boolean-Binding). Fix ist implementiert.

## Soll/Ist-Abgleich

1. Auth / OAuth: **🟡 Teilweise**
- PKCE S256-only ist implementiert.
- Authorization Code, Access/Refresh, Rotation, Reuse Detection, Revocation-Endpunkt sind implementiert.
- Legacy Bearer Tokens sind parallel aktiv.
- Offen: vollständige RFC7009-Details + saubere öffentliche Dokumentation für jeden Edge Case.

2. Multi-Tenant / Household-Sicherheit: **🟡 Teilweise**
- Household wird aus Token abgeleitet.
- Viele Endpunkte filtern korrekt auf `household_id`.
- Offen: Voll-Audit aller Endpunkte auf harte Household-Isolation als Checkliste.

3. Scopes: **🟡 Teilweise**
- Scope-Checks sind breit vorhanden.
- Offen: `cases:write` fehlt funktional (Open Cases bisher read-only API), dadurch Scope zwar geplant aber nicht vollständig genutzt.

4. Token-Sicherheit: **🟡 Teilweise**
- Tokens werden gehasht gespeichert, nicht im Klartext.
- Auth-Codes sind kurzlebig und one-time.
- `Cache-Control: no-store` auf OAuth-Token-Responses vorhanden.
- Offen: Token-Leaks in Logs systematisch ausschließen/testen.

5. API-Härtung: **🟡 Teilweise**
- Globales JSON-Error-Handling, `X-Request-ID` und `request_id` vorhanden.
- Offen: Idempotency-Cache-Antworten liefern aktuell nicht immer `request_id`.
- Offen: strukturierte Audit-/Request-Logs sind vorbereitet, aber nicht durchgängig aktiv verdrahtet.

6. CORS: **✅ Weitgehend erfüllt**
- Keine Wildcard, feste Allowlist (`chat.openai.com`, `chatgpt.com`, optional Dev-Origin).
- `OPTIONS` mit `204` vorhanden.

7. Rate Limiting: **🟡 Teilweise**
- Rate-Limit-Funktion existiert (Token/IP + `429` + `Retry-After`).
- Offen: aktuell nicht zentral in allen API-Endpunkten aufgerufen.

8. Idempotency für Writes: **🟡 Teilweise**
- Tabelle + Hilfsfunktionen vorhanden.
- Aktiv in `transactions`, `planned_payments`, `categories`, `payees`, `tags`.
- Offen: `cases` Write-API fehlt (damit auch deren Idempotency).

9. Audit Logging: **🟡 Teilweise**
- `api_audit_log` Tabelle + Helper vorhanden.
- Offen: Aufrufe sind nicht zentral erzwungen, daher unvollständige Abdeckung.

10. OpenAPI / GPT Schema: **🟡 Teilweise**
- Schema ist erweitert, inkl. neuer `planned_payments` Writes.
- Offen: OAuth/PKCE-Dokumentation und Scope-Mapping final gegen Code synchronisieren (public release quality gate).

11. Connected Apps UI: **🟡 Teilweise**
- OAuth-Apps inkl. Revocation im Household-UI vorhanden.
- Legacy API-Tokens verwaltbar.
- Offen: „last access“ und Scope-Details UX-seitig auf Vollständigkeit prüfen.

12. Category / Homelab Follow-up: **🟡 Teilweise**
- Category API inkl. Bulk-Reassign ist vorhanden.
- Offen: gezielte operative Migration (`Arbeit` -> `Homelab` + Payee-Regeln) als geführter Flow/Script.

13. Pflichttests vor öffentlich: **❌ Offen als Gesamtpaket**
- Einzelteile sind testbar, aber komplette Release-Testmatrix ist noch nicht automatisiert/abgehakt.

## Neue TODO in korrekter Reihenfolge

## P0 — Vor Public-Freigabe zwingend

1. Zentralen API-Middleware-Hook einführen:
- `hb_api_rate_limit(...)` und `hb_api_audit_log(...)` in allen API-Endpunkten verlässlich ausführen.

2. Einheitliches Error-Format überall erzwingen:
- Alle Endpunkte auf `hb_api_error(...)`/`hb_api_json(...)` vereinheitlichen.
- Sicherstellen, dass keine alten `{ "error": "..." }`-Antworten mehr verbleiben.

3. Idempotency-Response korrigieren:
- Bei Cache-Hits ebenfalls `request_id` konsistent zurückgeben.

4. `cases:write` API liefern:
- `POST/PATCH/DELETE /api/open_cases.php` inkl. Scope-Checks, Idempotency und Household-Filter.

5. Public Testmatrix 1–13 als ausführbares Testprotokoll abarbeiten:
- OAuth PKCE S256, refresh rotation + reuse detection, revoke, scope-blocking, household isolation, rate-limit, schema validation.

## P1 — Direkt danach

6. OpenAPI finalisieren (Public Quality):
- OAuth-Flow, PKCE-Hinweise, Scope-Anforderungen pro Operation, Legacy Bearer klar dokumentieren.

7. Connected Apps UI härten:
- „Last access“ und Scope-Anzeige verifizieren.
- Revocation-UX für OAuth + Legacy Token konsistent.

8. Structured Logging vervollständigen:
- Audit-Einträge mit Endpoint/Status/Request-ID für jede API-Operation.

## P2 — Produktiver Follow-up

9. Homelab-Migration als sicherer Assistent:
- Kategorie anlegen/umbennen, Bulk-Reassign, Payee-Mapping-Regeln.
- Dry-run + Confirm-Mode.

10. E2E-Automation:
- Shell-/PHP-Testskript unter `tools/` für reproduzierbare API-Abnahme vor jedem Release.

