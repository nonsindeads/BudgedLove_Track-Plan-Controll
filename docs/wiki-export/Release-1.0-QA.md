# BudgetLove 1.0 QA Checklist

Stand: 2026-05-14

## Ziel

Diese Checkliste beschreibt die letzten manuellen Pruefungen vor 1.0 Stable. Sie ist bewusst praktisch gehalten und soll mit echten, aber nicht in Git gespeicherten Daten ausgefuehrt werden.

## 1. Onboarding

- Neuen Nutzer registrieren.
- Pruefen, dass Login vor Admin-Freischaltung blockiert ist.
- Nutzer als Admin freischalten.
- Mit neuem Nutzer einloggen.
- Haushalt mit primaerem Konto anlegen.
- Periodenmodus `Start of month` testen.
- Periodenmodus `Salary day` mit Gehaltstag testen.
- Periodenmodus `Actual salary payment` testen und Fallback ohne Gehaltsbuchung pruefen.
- Nach Import/Anlage einer Gehaltsbuchung pruefen, dass die Periode korrekt vom echten Gehaltseingang startet.

## 2. Belegentwurf Zu Bankimport

- Per Custom GPT oder MCP einen Beleg als Draft ueber `POST /api/transaction_drafts.php` anlegen.
- Draft in offenen Buchungen pruefen.
- Kontoauszug mit passender Buchung importieren.
- Pruefen, dass der Draft gematched wird.
- Pruefen, dass keine Dublette entsteht.
- Gematchte offene Buchung finalisieren.
- Kategorie, Empfaenger, Tags und Notiz pruefen.

### 2.1 Reproduzierbarer E2E-Lauf (Draft -> Import -> Kein Duplikat)

Ziel:
- Ein API-erzeugter Draft wird beim CAMT-Import erkannt und gematched.
- Es entsteht keine zweite offene Buchung fuer denselben Umsatz.

Voraussetzungen:
- Test-Haushalt mit mindestens einem Konto.
- API-Token mit `transactions:write`.
- CAMT/XML-Testdatei fuer genau eine Buchung (Betrag > 0).

Ablauf:
1. Per API einen Draft erzeugen:
   - `type`: `expense` oder `income`
   - `amount`: exakt wie in der CAMT-Buchung
   - `date`: innerhalb des Import-Matching-Fensters (ca. +/- 5 Tage)
   - `payee`: moeglichst identisch zum CAMT-Gegenkonto
2. In `Open bookings` pruefen:
   - Draft ist vorhanden
   - `is_reviewed = false`
   - Notiz enthaelt `[receipt-draft]`
3. CAMT/XML importieren (`/import.php`) auf dasselbe Konto.
4. Import-Resultat pruefen:
   - `Matched` steigt um mindestens `1`
   - `Inserted` steigt fuer diesen Umsatz nicht zusaetzlich
5. Datenpruefung:
   - Es gibt nur einen Datensatz mit dem finalen `import_hash` dieses Umsatzes.
   - Gematchter Draft hat jetzt `import_hash` gesetzt.
   - Keine zweite offene Transaktion mit gleichem Betrag/Datum/Gegenkonto.
6. Finalisierung:
   - Gematchte offene Buchung finalisieren.
   - Kategorie/Payee/Tags/Notiz plausibel pruefen.

Tooling-Hilfe:
- `tools/api/e2e-draft-import-check.sh create`
  erzeugt einen Test-Draft und eine passende CAMT-XML in `/tmp`.
- `tools/api/e2e-draft-import-check.sh verify --state /tmp/budgetlove-e2e-draft-*.json`
  prueft nach dem Import, dass der Draft gematched wurde und keine Dublette offen ist.

Akzeptanzkriterien:
- Matching greift stabil bei identischem Betrag + passendem Datum + passendem Payee-Hinweis.
- Kein Dubletten-Eintrag nach wiederholtem Import derselben CAMT-Datei.
- Audit/History zeigt den Importlauf nachvollziehbar.

Bekannte Grenzfaelle:
- Abweichende Payee-Schreibweisen koennen Matching verschlechtern.
- Wenn Draft ausserhalb des Datumsfensters liegt, wird eher neu eingefuegt.
- Negative oder Null-Betraege werden im Import blockiert.

## 3. Zahlen Und Forecast

- Dashboard gegen bekannte Kontostaende pruefen.
- `Free this period` gegen Einkommen, Fixkosten, offene Plaene und variable Ausgaben pruefen.
- Forecast-Linie mit offenen Zahlungen pruefen.
- Negativsaldo-Warnung erzwingen und danach wieder entfernen.
- `Can I afford this?` mit positivem und negativem Szenario testen.

## 4. Mobile

- Dashboard auf Smartphone-Breite pruefen.
- Bottom-Sheet Quick-Add oeffnen und Buchung speichern.
- Kategorien- und Tag-Drilldown oeffnen/schliessen.
- Budgets und Savings auf Smartphone-Breite pruefen.
- Recurring-Zahlungen auf Smartphone-Breite pruefen.
- Open Cases und Ratenplan auf Smartphone-Breite pruefen.

## 5. Import Und Matching

- CAMT/XML Einzeldatei importieren.
- ZIP mit mehreren XML-Dateien importieren.
- Dublettenimport wiederholen und blockierte Eintraege pruefen.
- Payee-Mapping aus unzugeordneten Buchungen pruefen.
- Wiederkehrende Zahlungen gegen importierte Buchungen matchen.

## 6. Public Release Hygiene

- `git status` muss sauber sein.
- `git diff compose/` vor jedem Infra-Touch pruefen.
- Keine Tokens, echten Bankdaten oder produktiven Secrets in Git.
- `CHANGELOG.md` fuer alle seit 0.25.1 gebauten Features aktualisieren.
- `VERSION` final setzen.
- Full-License-Text fuer AGPL-3.0-or-later eintragen.
- Release Notes fuer 1.0 schreiben.
