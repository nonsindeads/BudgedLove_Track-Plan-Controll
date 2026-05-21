# BudgetLove Feature-Konzept — Wunschliste & Spartöpfe

Stand: 2026-05-15  
Status: Geprüft + Umsetzungsplanung erstellt (noch nicht implementiert)

## 1) Konzeptprüfung (Ergebnis)

Dein Konzept ist fachlich stimmig und passt sauber zur BudgetLove-Positionierung:

- **Open Cases** = Verpflichtungen
- **Planned Payments** = erwartete Zahlungen
- **Saving Goals** = freiwillige positive Ziele

Wichtige Produktwirkung:
- Das Feature stärkt Planung, Motivation und „frei verfügbar“-Transparenz.
- Der GPT-Use-Case wird deutlich besser, weil Ziele + Reserven in Entscheidungen einfließen.

## 2) Konsolidierte Fachdomain (inkl. bereits notierter Storage-Typen)

Wir führen `saving_goals` ein und integrieren direkt die Erweiterung:

- `storage_type`: `virtual | cash | external_account`
- `source_account_id` nullable
- `storage_account_id` nullable
- `cash_location` nullable

Damit werden beide Anforderungen zusammengeführt:

1. Wunschliste/Spartöpfe
2. Korrekte Abbildung, **wo** das Geld tatsächlich liegt

## 3) Datenmodell (Soll)

## 3.1 Tabelle `saving_goals`

- `id`
- `household_id`
- `name`
- `description` nullable
- `target_amount_cents`
- `current_amount_cents`
- `target_date` nullable
- `monthly_contribution_cents` nullable
- `source_account_id` nullable
- `storage_account_id` nullable
- `cash_location` nullable
- `category_id` nullable
- `priority` (`low | normal | high`)
- `status` (`active | paused | completed | archived`)
- `is_optional` boolean default true
- `storage_type` (`virtual | cash | external_account`) default `virtual`
- `created_at`
- `updated_at`
- `completed_at` nullable

## 3.2 Tabelle `saving_goal_contributions`

- `id`
- `household_id`
- `saving_goal_id`
- `transaction_id` nullable
- `planned_payment_id` nullable
- `amount_cents` (positiv/negativ)
- `contribution_date`
- `note` nullable
- `created_at`

## 3.3 Optionales Feld

- `planned_payments.saving_goal_id` nullable references `saving_goals(id)`

## 4) Validierungsregeln

- `name` required
- `target_amount_cents > 0`
- `current_amount_cents >= 0`
- `monthly_contribution_cents >= 0` (wenn gesetzt)
- `priority in (low, normal, high)`
- `status in (active, paused, completed, archived)`
- `storage_type in (virtual, cash, external_account)`
- `category_id` und Accounts müssen zum Household gehören
- `saving_goal_id` in contributions muss zum Household gehören
- `current_amount_cents` darf `target_amount_cents` überschreiten (UI: „übererfüllt“)

Storage-spezifisch:
- `virtual`: `source_account_id` erforderlich
- `cash`: `source_account_id` optional, `cash_location` optional
- `external_account`: `storage_account_id` erforderlich

## 5) Berechnungslogik

## 5.1 Frei verfügbar pro Konto

`frei_verfuegbar = konto_saldo - summe_virtual_reserven_auf_diesem_konto`

Regeln:
- `virtual`: reduziert frei verfügbar auf `source_account_id`
- `cash`: reduziert nicht Bank-Kontostand (physisch weggelegt)
- `external_account`: zählt auf `storage_account_id`; keine zusätzliche virtuelle Reservierung auf Giro

## 5.2 Forecast

Pro Ziel:
- `remaining = target_amount - current_amount`
- `months_needed = ceil(remaining / monthly_contribution)` falls Beitrag > 0
- `required_monthly = remaining / months_until_target_date` falls Datum gesetzt

Wenn kein monatlicher Beitrag gesetzt:
- Hinweis „Keine automatische Rücklage geplant“

## 6) API-Design (Soll)

Scopes:
- `goals:read`
- `goals:write`

Endpoints:
- `GET /api/saving-goals`
- `GET /api/saving-goals?id=...`
- `POST /api/saving-goals` (Idempotency-Key)
- `PATCH /api/saving-goals?id=...` (Idempotency-Key)
- `DELETE /api/saving-goals?id=...` (soft delete -> `status=archived`)
- `POST /api/saving-goals/contributions` (Idempotency-Key, atomare Update-Logik)
- `GET /api/saving-goals/contributions?saving_goal_id=...`
- `GET /api/analytics/saving-goals`

## 7) UI-Konzept (MVP)

Neue Seite: `Wünsche & Spartöpfe`

Karte je Ziel:
- Name
- Zielbetrag / aktueller Betrag
- Fortschrittsbalken
- monatliche Rücklage
- Zielmonat
- Priorität
- Status
- Aktionen: Beitrag + / Entnahme - / pausieren / abschließen / archivieren

Formular „Neues Ziel“:
- „Wo liegt das Geld?“
  - virtuell reservieren
  - bar zurückgelegt
  - separates Konto / Tagesgeld

## 8) Sicherheits- und Architekturvorgaben

- `household_id` immer aus Auth-Kontext
- keine Household-ID aus Request übernehmen
- Scope-Prüfung pro Endpoint
- JSON-Errors im Standardformat
- Audit-Log + Rate-Limit + Idempotency auch für neue Goals-Endpunkte
- keine Stacktraces nach außen

## 9) Technische Umsetzungsplanung (Schrittfolge)

## Phase A — DB + Domain-Basis
1. Migration: `saving_goals`
2. Migration: `saving_goal_contributions`
3. Optional Migration: `planned_payments.saving_goal_id`
4. Indizes:
   - `saving_goals(household_id)`
   - `saving_goals(household_id, status)`
   - `saving_goal_contributions(saving_goal_id)`
   - `saving_goal_contributions(household_id, contribution_date)`
5. Domain-Validierung + Helper in `app/`

## Phase B — API MVP
1. `public/api/saving_goals.php` (GET/POST/PATCH/DELETE soft)
2. `public/api/saving_goal_contributions.php` (GET/POST)
3. Atomare contribution-Operation:
   - Insert contribution
   - Update `saving_goals.current_amount_cents`
   - innerhalb einer DB-Transaktion
4. Scopes erweitern:
   - OAuth client scopes + Schema
5. OpenAPI ergänzen:
   - `SavingGoal`, `SavingGoalContribution`, `SavingGoalAnalytics`

## Phase C — UI MVP
1. Neue Seite `public/saving_goals.php`
2. CRUD-Formular + Statuswechsel
3. Beitragsliste und +/− Beitrag buchen
4. Anzeige „frei verfügbar je Konto“ (mind. für `virtual`)

## Phase D — Analytics/Forecast
1. `GET /api/analytics/saving-goals`
2. Zielprognosen (`months_needed`, `required_monthly`)
3. Zusammenführung im Dashboard (optional in v1.1)

## Phase E — GPT/Actions
1. Actions-Schema erweitern
2. GPT-Use-Cases testen:
   - Ziel anlegen
   - Restbetrag
   - zusätzliche Rücklage möglich?
   - Entnahme / Rückbuchung

## 10) MVP-Schnitt (für erste Lieferung)

Enthalten:
- `saving_goals` CRUD
- `contributions` buchen
- Fortschritt
- frei verfügbar je Konto (virtual korrekt)
- OpenAPI + Scopes + Idempotency

Nicht im MVP:
- automatische monatliche echte Bankbuchung
- Bilder für Wünsche
- Priorisierungs-Engine bei Budgetknappheit
- GPT-Empfehlungslogik über MVP hinaus

## 11) Offene Architekturentscheidung (vor Implementierung klären)

Für monatliche Rücklagen:

Option A (empfohlen für MVP):
- Nur `saving_goal_contributions` (separat), keine echte Transaktion.
- Vorteil: keine Verfälschung Ausgabenstatistik.

Option B (später):
- Verknüpfung mit `planned_payments` als „Reserve“-Typ/Markierung.

Empfehlung:
- **MVP mit Option A** starten.
- `planned_payments.saving_goal_id` trotzdem vorbereiten.

