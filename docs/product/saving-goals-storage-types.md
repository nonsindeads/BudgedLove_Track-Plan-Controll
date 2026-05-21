# Saving Goals Erweiterung — Storage Types

Stand: 2026-05-15  
Status: Backlog / noch nicht implementiert

## Ziel

`saving_goal` soll abbilden können, wo das Geld tatsächlich liegt:

- `virtual`
- `cash`
- `external_account`

Damit wird verhindert, dass Rücklagen doppelt oder falsch gegen Kontosalden gerechnet werden.

## Datenmodell (Soll)

Zusätzliche Felder für `saving_goals`:

- `storage_type` (`virtual | cash | external_account`) `not null`, default `virtual`
- `source_account_id` `nullable`
- `storage_account_id` `nullable`
- `cash_location` `nullable`

## Semantik

### 1) `virtual`
- Geld bleibt auf einem normalen Konto (z. B. Giro).
- BudgetLove reserviert nur einen Anteil virtuell.
- Regel: `source_account_id` erforderlich.

Beispiel:
- Konto `SPK 80`: `1.000 €`
- Goal `Laufschuhe`: `100 €` virtuell reserviert.

### 2) `cash`
- Geld wird physisch zurückgelegt.
- Optionales Feld `cash_location`, z. B.:
  - Zuhause
  - Umschlag
  - Spardose
  - Tresor
- Regel: `source_account_id` optional, `cash_location` optional.

Beispiel:
- `10 €` monatlich in Umschlag „Laufschuhe“.

### 3) `external_account`
- Geld liegt auf separatem Konto (z. B. Tagesgeld).
- Regel: `storage_account_id` erforderlich.
- Wichtig: Nicht zusätzlich als virtuelle Reservierung auf dem Girokonto zählen.

Beispiel:
- `Urlaub` liegt auf `Tagesgeldkonto`.

## Validierungsregeln

- `storage_type = virtual`:
  - `source_account_id` muss gesetzt sein.
  - `storage_account_id` muss `null` sein.
- `storage_type = cash`:
  - `source_account_id` optional.
  - `storage_account_id` muss `null` sein.
- `storage_type = external_account`:
  - `storage_account_id` muss gesetzt sein.
  - `source_account_id` optional (nur für Herkunft/Tracking).
- `cash_location` nur bei `storage_type = cash` sinnvoll (sonst `null`).

## Auswirkungen auf Berechnung „frei verfügbar“

- `virtual`: reduziert frei verfügbares Geld des Quellkontos.
- `cash`: reduziert nicht den aktuellen Bank-Kontostand; optional als Bar-Entnahme darstellen.
- `external_account`: zählt als eigenes Konto/Rücklagenkonto; keine doppelte virtuelle Reservierung auf dem Quellkonto.

## Forecast-Regeln

- `virtual`: monatliche Rücklage reduziert verfügbare Liquidität des Quellkontos.
- `cash`: kann als geplante Bar-Entnahme geführt werden.
- `external_account`: idealerweise als geplanter Transfer (z. B. Giro -> Tagesgeld).

## UI-Anpassung (Soll)

Beim Anlegen/Bearbeiten eines Spartopfs:

Frage: **„Wo liegt das Geld?“**

- Nur virtuell reservieren
- Bar zurückgelegt
- Auf separatem Konto / Tagesgeld

Kontextfelder dynamisch:

- Bei `virtual`: Kontoauswahl für `source_account_id`
- Bei `cash`: optional `cash_location`
- Bei `external_account`: Kontoauswahl für `storage_account_id`

## Beispiele

- Laufschuhe virtuell auf SPK 80
- Urlaub auf Tagesgeldkonto
- Bargeld-Umschlag zuhause
- Homelab virtuell im Girokonto

## Umsetzungs-TODO

1. DB-Migration für neue Felder in `saving_goals`.
2. Domain-/Repository-Layer für Validierungen nach `storage_type`.
3. UI-Formular (create/edit) mit dynamischen Feldern und Hilfetexten.
4. Budget-/Forecast-Berechnungen anpassen (keine Doppelzählung).
5. API/OpenAPI für `saving_goals` erweitern.
6. Migrationstest mit bestehenden `saving_goals` (`storage_type=virtual` als Default).
7. Rechenbeispiele als Regressionstests (virtual/cash/external_account).

