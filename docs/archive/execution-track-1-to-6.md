# BudgetLove Execution Track 1-6

Statusdatum: 2026-05-18

## 1) Kategorie-/Payee-Regeln automatisieren

Status: In Umsetzung (Basis fertig)

- Import-Mapping unterstuetzt jetzt exakte Regeln und Musterregeln (`*` oder `%`) fuer Counterparty-Namen.
- `payee_mapping.php` erlaubt jetzt das Anlegen manueller Regeln inkl. Wildcards.

Offen:
- Optionaler Regel-Import aus bestehenden Buchungen.
- Priorisierung bei mehreren passenden Musterregeln.

## 2) Bankimport-Matching transparenter machen

Status: In Umsetzung (Basis fertig)

- Import-Detailtabelle zeigt jetzt pro Buchung den Match-Grund:
  - Receipt draft match
  - Receipt split match
  - Rule: exact payee mapping
  - Rule: wildcard payee mapping
  - No rule

Offen:
- Match-Transparenz auch in API-Antwort fuer Automation-Clients bereitstellen.

## 3) Saving Goals / Wunschliste MVP

Status: Gestartet (technische Basis)

- Release-Migration `0.30.5`:
  - `saving_goals`
  - `saving_goal_contributions`
  - optionales `planned_payments.saving_goal_id`
- Neues API-Endpoint:
  - `public/api/saving-goals.php`
  - CRUD fuer Ziele
  - Beitragsbuchung (`resource=contributions`) mit atomarem Update von `current_amount_cents`
  - `storage_type`: `virtual`, `cash`, `external_account`

Offen:
- OpenAPI-Schema fuer GPT erweitern.
- UI-Seite fuer Saving Goals bauen.
- Forecast-/free-balance-Integration.

## 4) Public API / OAuth Stufe

Status: Vorbereitet, nicht umgesetzt

Offen:
- Public-OAuth-Action-Schema unter 30 Operationen erstellen.
- Scope-Matrix gegen Endpunkte gegenpruefen.
- E2E-Test mit zweitem Haushalt (Isolation).

## 5) Cloud-DB-Flow haerten

Status: Teilweise vorhanden

Offen:
- Session-Lifecycle (Start/Stop/GC) mit Fehlerklassen pruefen.
- Klarere Fehlermeldungen fuer Cloud-I/O im UI.
- Verifikation: kein stiller Fallback bei Cloud-Session-Fehlern.

## 6) Doku / Tests / Release-Readiness

Status: Gestartet

Offen:
- QA-Checkliste fuer Split-Konvertierung bestehender Buchungen.
- API-Tests fuer `saving-goals.php`.
- GPT-Action-Hinweise auf neuen Split-Update-Flow konsolidieren.
