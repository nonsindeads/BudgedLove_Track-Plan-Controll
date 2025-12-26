# Haushaltsbuch Domänenmodell (MVP)

## Haushalte
- `households`: Name, Währung, Monatsschluss-Modus (`first_of_month` oder `salary_day` + optional `salary_day`), Timestamps.
- `household_members`: Zuordnung User ↔ Haushalt mit Rollen `admin|editor|viewer`, `is_active`. Primärschlüssel (household_id, user_id).
- Standard: Nach Login muss ein User einen Haushalt wählen oder neu anlegen. Admin-Rolle im Haushalt erhält der Ersteller.
- Default-Kategorien/Tags werden aus globalen Datensätzen (`household_id null`) in den neuen Haushalt kopiert.

## Konten
- `accounts`: Haushalt, Name, Typ (`cash|checking|savings|credit_card|loan|asset|liability|other`), Währung, Startsaldo (in Cent), Archiv-Flag.
- Jeder Account gehört genau zu einem Haushalt.

## Kategorien & Tags
- `categories`: Haushalt oder global (NULL = Default), Name, Typ (`income|expense`), optionale Eltern-ID (nur gleiche Household-ID), Sortierung, Aktiv-Flag.
- `tags`: Haushalt oder global (NULL), Name, optionale Farbe, Aktiv-Flag.
- Admin kann globale Defaults pflegen; beim Household-Setup werden sie kopiert.

## Payees
- `payees`: Haushalt, Name (unique pro Haushalt), optionale Adresse/IBAN/BIC/Notizen.

## Transaktionen
- `transactions`: Haushalt, Typ (`income|expense|transfer`), Buchungsdatum, Betrag in Cent (immer positiv, Typ steuert Richtung), Währung, Konto, Kategorie, Payee, Notiz, Transfer-Quell/Zielkonto, optionale Import-IDs, optionale Verknüpfung zu `planned_payments`.
- `transaction_splits`: Aufteilung Betrag auf mehrere Kategorien (Summe = `transactions.amount_cents`).
- `transaction_tags`: Zuordnung Transaktion ↔ Tag.
- Darstellung: Betrag wird je nach Typ als Zu-/Abgang interpretiert; gespeichert werden Cent als positive Ganzzahlen.

### Transfers
- Typ `transfer` nutzt `transfer_from_account_id` und `transfer_to_account_id` (beide Pflicht). `account_id`/`category_id` sind NULL.

### Splits
- Bei Einkommen/Ausgabe kann Kategorie leer bleiben, sofern Splits vorhanden sind. Die Summe der Splits muss dem Betrag entsprechen.

## Wiederkehrende Regeln & Tasks
- `recurring_rules`: Haushalt, aktiv-Flag, Name, Kind (`transaction|task`), Schedule (unit `day|week|month|year`, Interval, optionale Weekdays `mon,tue`, optionale `schedule_monthday`, Start-/Next/Last), Payload als JSON.
- `tasks`: Haushalt, Titel/Beschreibung, Due Date, optionaler Betrag in Cent, Status (`open|done|cancelled`), Herkunftsregel.
- `recurring_executions`: Log der ausgeführten Regeln.
- `cron.php` erzeugt aus fälligen Regeln neue Transaktionen/Tasks und plant `next_run_at` fortlaufend.

## Planbasierte Zahlungen
- `recurring_payments`: Haushalt, Name, Richtung (`income|expense`), Betrag, Intervall (`day|week|month|year` + Wert), Startdatum, Priorität, optional/mandatory, Konto/Kategorie/Payee/Notiz, aktiv.
- `planned_payments`: Monatliche Planungspunkte (aus Recurring oder manuell), Datum, Status (`open|done|skipped|overdue|suggested`), Priorität, optional/mandatory, Zuordnung zu Konto/Kategorie/Payee, optionale Verknüpfung zu Transaktion.
- Statusregeln: überfällig = geplantes Datum < heute bei Status `open`; `done`/`skipped` schließen den Punkt.

## Offene Posten & Monatsabschluss
- `open_cases`: Offene Posten mit Status (`open|clarifying|agreed|done`), Referenz/Aktenzeichen, Kontakt, Notizen, optionale Verknüpfung zu Einmal- oder Recurring-Zahlung.
- `month_closures`: Abschlüsse pro Haushaltszeitraum (Start/Ende, geschlossen von User, Notiz).

## Audit & Live
- `audit_events`: Historie aller Änderungen (old/new JSON, User/Haushalt, Tabelle, Aktion).
- `chat_messages`: Live-Chat pro Haushalt (WS feed).
- `row_version`: Optimistic Locking auf allen Core-Tabellen.

## Anhänge
- `attachments`: Haushalt, optional Transaction-ID, Original- und gespeicherter Dateiname, MIME, Größe, Speicherpfad, optionale Paperless-ID.
- Dateien liegen unter `HB_UPLOAD_DIR` (default `/srv/haushaltsbuch/uploads/<household_id>/`) außerhalb des Webroots und werden über `attachments.php` ausgeliefert.

## Betragsdarstellung
- Alle Geldbeträge als Integer-Cents. Eingaben werden aus Dezimalstrings geparsed, intern positiv gespeichert; Interpretation (Soll/Haben) via Typ.
