# BudgetLove Public API Roadmap

Stand: 2026-05-21
App-Version: 0.30.18
Status: Private/Closed-Beta API produktiv nutzbar, Public API noch nicht freigegeben.

## Aktueller Stand

BudgetLove hat eine nutzbare private API fuer CustomGPT, MCP und eigene Automatisierungen. Der aktuelle produktive Zugriff erfolgt ueber Legacy-Bearer-Tokens pro Nutzer/Haushalt. Das ist fuer private Nutzung und Closed Beta ausreichend, aber noch nicht fuer einen oeffentlichen Multi-User-GPT.

Aktive API-Bereiche:

- Metadaten: Konten, Kategorien, Kontextdaten
- Transaktionen: lesen, erstellen, aktualisieren, loeschen
- Transaktionsentwuerfe: erstellen, spaeter mit Bankimport abgleichen
- Belege: lesen, OCR/Upload verarbeiten, aktualisieren, archivieren
- Split-Buchungen: Gruppen lesen, erstellen, aktualisieren, archivieren
- Geplante Zahlungen: lesen, erstellen, aktualisieren, loeschen
- Offene Posten: lesen, erstellen, aktualisieren, loeschen
- Empfaenger: lesen, erstellen, aktualisieren, loeschen
- Tags: lesen, erstellen, aktualisieren, loeschen
- Kategorien: lesen, erstellen, aktualisieren, loeschen, Bulk-Reassign
- Wiederkehrende Regeln: lesen
- Spartopf-/Saving-Goals-MVP: lesen, erstellen, aktualisieren, archivieren; Contributions hinzufuegen/loeschen
- Analytics: Monats-, Kategorien-, Payee-, Tag-, offene-Planungen- und Duplikat-Auswertungen

Aktuelle OpenAPI-Dateien:

- `customgpt-booking-actions.yaml`: BudgetLove GPT - Daily Actions
- `customgpt-planning-actions.yaml`: BudgetLove GPT - Planning
- `customgpt-openapi.yaml`: interne Gesamtreferenz
- `customgpt-public-oauth-actions.yaml`: OAuth/Public-GPT-Entwurf, noch nicht Public-Ready

Das alte kleine `openapi.yaml` wurde als Legacy-Datei archiviert, weil es den aktuellen API-Umfang nicht mehr korrekt abbildet.

## Was Bereits Gehaertet Ist

- Household-Kontext wird serverseitig aus Auth/Session/Token abgeleitet.
- Schreibende GPT-Aktionen nutzen Idempotency-Key, wo bereits umgesetzt.
- JSON-Fehlerformat, Request-ID, Scopes und Audit-Logging sind in den API-Basisfunktionen vorbereitet.
- Planned-Payments-Backward-Compatibility ist beruecksichtigt:
  - `resolved` wird weiterhin akzeptiert bzw. kompatibel behandelt.
  - Prioritaeten koennen numerisch oder als Textwerte verarbeitet werden, soweit vom Endpoint vorgesehen.
  - Idempotency-Konflikte muessen `409 conflict` liefern.
- CustomGPT ist wegen des 30-Operationen-Limits in zwei GPTs aufgeteilt:
  - Daily Actions fuer Buchen, Belege, Kategorien, Payees, Tags, Splits
  - Planning fuer Planung, offene Posten, wiederkehrende Regeln, Saving Goals, Analytics

## Noch Nicht Public-Ready

Vor echter Veroeffentlichung fehlen bzw. muessen abschliessend verifiziert werden:

- OAuth Authorization Code Flow mit PKCE S256 fuer jeden Nutzer
- kurzlebige Access Tokens und rotierende Refresh Tokens
- Token-Reuse-Detection und RFC7009-Revocation
- Connected-Apps-UI fuer Widerruf und Uebersicht
- Scope-Pruefung fuer jeden Endpoint
- Rate Limiting mit `429` und `Retry-After`
- vollstaendige Audit-Logs fuer alle API-Calls
- Haushalts-Isolation mit zweitem Testhaushalt
- einheitliche JSON-Fehler ohne HTML, PHP-Warnings oder Stacktraces
- CORS-Allowlist fuer GPT/ChatGPT und lokale Entwicklung
- OpenAPI-End-to-End-Test im GPT Builder

## Naechste Reihenfolge

1. OAuth-End-to-End im GPT Builder testen.
2. Scope-Fehler gezielt testen: falscher Scope muss blockieren.
3. Household-Isolation mit zweitem Testhaushalt pruefen.
4. Idempotency-Key fuer alle Schreiboperationen testen.
5. Rate-Limit-Verhalten testen.
6. Revoke/Disconnect testen.
7. OpenAPI mit dem tatsaechlichen Endpoint-Verhalten abgleichen.
8. Public-OAuth-Schema erst danach als nutzbares GPT-Schema freigeben.

## Technische Leitplanken

- `household_id` wird niemals aus Request-Payloads uebernommen.
- Alle Queries muessen den Haushalt aus dem Auth-Kontext erzwingen.
- Tokens werden nie im Klartext gespeichert oder geloggt.
- Schreiboperationen muessen idempotent sein, wenn GPT/Clients wiederholen.
- Deletes sind bevorzugt Soft-Deletes bzw. Archivierungen.
- Bestehende Legacy-Bearer-Token bleiben fuer private Integrationen erhalten, sind aber getrennt von Public-OAuth zu behandeln.

## Zielbild Public GPT

Der oeffentliche BudgetLove GPT soll ohne globalen API-Key funktionieren. Jeder Nutzer verbindet seinen eigenen BudgetLove-Account per OAuth, bestaetigt Scopes und kann anschliessend nur den eigenen Haushalt lesen oder bearbeiten.

Public-Ready heisst: Kein Fremdhaushalt ist per ID erratbar oder abrufbar, falsche Scopes werden geblockt, Tokens sind widerrufbar, GPT-Retries erzeugen keine Doppelbuchungen und Fehler sind fuer Nutzer nachvollziehbar, aber ohne interne Details.
