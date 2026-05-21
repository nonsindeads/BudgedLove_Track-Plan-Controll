# BudgetLove 1.0 Roadmap

Stand: 2026-05-14

## Status

BudgetLove ist aktuell im Closed-Beta-Status. Registrierung ist moeglich, produktiver Zugriff erfolgt aber nur nach Admin-Freischaltung. Bis zum Public Launch bleiben Freischaltungen bewusst manuell.

Der Kern ist weitgehend umgesetzt:

- Haushalte, Benutzer, Rollen und Admin-Freischaltung
- Konto-, Kategorie-, Tag- und Empfaengerverwaltung
- Kontoauszugsimport mit offenen Buchungen
- Belegentwuerfe per API fuer ChatGPT/Claude
- Periodenlogik mit Monats-, Gehaltstags- und echtem Gehaltseingang-Modus
- Budgets, Sparplaene, Forecast, Open Cases und Raten
- MCP-Server und Custom-GPT-Action-Schema

## Produktpositionierung

BudgetLove bleibt self-hosted und datenhoheitlich. Die Daten gehoeren dem Nutzer und bleiben in seiner eigenen Instanz, auch wenn die Instanz nicht auf dem Heim-PC, sondern auf einem eigenen VPS oder gemieteten Server laeuft.

Wichtige Aussage fuer 1.0:

- Kein Cloud-Zwang
- Keine Telemetrie
- Keine zentrale BudgetLove-Datenbank
- Betrieb auf eigenem Server moeglich
- Zugriff von mehreren Geraeten ueber HTTPS/VPN moeglich
- API-Nutzung mit KI moeglich, ohne dass BudgetLove selbst Daten an Drittanbieter sendet

## Muss Vor 1.0

1. End-to-End-Test Belegentwurf zu Kontoauszug
   ChatGPT/Claude legt Belege als Draft an, spaeterer Bankimport matched diese Drafts und erzeugt keine Dubletten.

2. Konto- und Haushalts-Onboarding pruefen
   Neue Nutzer muessen bei der Einrichtung entscheiden koennen, ob BudgetLove nach Kalendermonat, festem Gehaltstag oder echtem Gehaltseingang rechnet. Die Einstellung muss spaeter durch Haushalts-Admins aenderbar bleiben.

3. Closed-Beta-Freischaltung beibehalten
   Registrierte Nutzer bleiben inaktiv, bis ein Admin sie freischaltet. Oeffentliche Selbstfreischaltung ist kein 1.0-Ziel.

4. Cloud-Anbindung klaeren
   Ziel ist keine zentrale BudgetLove-Cloud, sondern optionale Anbindung an eigene Speicherziele, z. B. Nextcloud/WebDAV/S3-kompatibler Speicher fuer Backups, Exporte und Belege.
   iCloud-first Planung inkl. spaeterer SQLite-Snapshot-Nutzung ist in `docs/cloud/cloud-drives-sqlite-plan.md` dokumentiert.

5. SQLite-Moeglichkeit bewerten
   SQLite soll als Single-User-/Kleinstinstanz-Option geprueft werden. PostgreSQL bleibt vorerst die stabile Hauptdatenbank, weil aktuelle Queries, Migrations und Arrays darauf optimiert sind.
   Fuer Cloud-Nutzung gilt dabei: kein Multi-Writer, sondern Snapshot/Backup-Ansatz.

6. Datenintegritaet und Zahlen pruefen
   Dashboard, Forecast, Perioden, Budgets, offene Posten, Raten und Bankimport muessen mit echten Daten gegen Plausibilitaet getestet werden. Die konkrete Pruefliste liegt in `docs/qa/release-1.0-checklist.md`.

7. Mobile QA
   Dashboard, Budgets, Recurring, Open Cases, Kategorien/Tags Drilldown und Transaktionen muessen auf Smartphone-Breite sauber nutzbar sein.

8. Public-Repo-Hygiene
   Root-README, Lizenz, CONTRIBUTING, CHANGELOG, Version und Release Notes muessen vor Public Launch sauber sein. Root-README, CONTRIBUTING und AGPL-SPDX-Hinweis sind angelegt; Full-License-Text und Release Notes bleiben offen.

9. Caddy-/Infra-Split entscheiden
   Nicht-App-Routen wie cloud, dns, git, code-* oder retrogaming gehoeren nicht dauerhaft in dieses App-Repo.

10. Security Review
    Keine Tokens, echten personenbezogenen Daten oder produktiven Secrets duerfen in Git liegen. Default-Zugaenge duerfen nur als lokale Entwicklungsdefaults dokumentiert sein.

11. GPT-Belegupload als echte Datei persistieren
   Der aktuelle GPT-Flow erzeugt Belegentwuerfe, speichert aber den eigentlichen Beleg nicht zwingend als Attachment. Fuer 1.0 muss bei Receipt-Uploads optional/konfigurierbar ein echter Datei-Upload in `attachments` erfolgen (inkl. `storage_path`, Household-Zuordnung und spaeterem Restore ueber Cloud-Migration).

## Feature-Completion Fuer 1.0

1. Mobile Bottom-Sheet Quick-Add
   Schnelles Erfassen mit Smart Defaults: Betrag, letztes Konto, letzte Kategorie, letzter Empfaenger und Tags.

2. Kann-ich-mir-das-leisten-Helfer
   Interaktiver Forecast-Slider fuer geplante Ausgaben. Ergebnis zeigt, ob und wann der Saldo kritisch wird.

3. Trend-Sparkline pro Kategorie, Tag und Empfaenger
   Reports sollen je Eintrag eine 12-Monats-Mini-Chart zeigen, damit Ausreisser und steigende Kosten sofort sichtbar werden.

4. Recurring-Health-Score
   Wiederkehrende Zahlungen sollen anzeigen, ob die letzten 2-3 erwarteten Termine gematcht wurden oder ob die Regel moeglicherweise nicht mehr aktiv ist.

5. Payee-Mapping aus Reports-Unassigned
   Reports sollen aus nicht zugeordneten Empfaengern/Kategorien direkt eine Mapping-Regel vorbefuellen koennen.

6. Demo-Daten und Demo-Instanz
   Neue Nutzer sollen BudgetLove gefahrlos mit Beispieldaten testen koennen.

7. Setup unter 10 Minuten
   Docker-Setup mit `.env.example`, klarer README und Migrationshinweisen.

8. Cloud-SQLite-QA-Pass
   Vor groesserer Nutzung des Cloud-Modus muss der komplette Zyklus aus Login, SQLite-Snapshot, Bearbeitung, Receipt-Upload, Session-Takeover, Empty-Snapshot-Recovery und CAMT-Import durchgespielt werden. Checkliste: `docs/cloud/runtime-hardening-checklist.md`.

9. API-QA fuer Splits und Saving Goals
   Vor 0.31.0 oder breiterem GPT-Einsatz muss `docs/qa/api-splits-saving-goals.md` durchlaufen werden.

## Nach 1.0 Vorgemerkt

1. Split-Belege fuer gemischte Einkaeufe
   KI- und Import-Workflows sollen Belege mit mehreren Warengruppen sauber aufteilen koennen, z. B. Lebensmittel, Haushalt, Kinderbedarf oder Gesundheit innerhalb eines einzelnen EDEKA- oder DM-Belegs.

2. Zwei Umsetzungsstufen fuer Split-Belege
   Kurzfristig sollen mehrere Transaction Drafts aus einem Beleg erzeugt werden koennen. Langfristig soll daraus eine echte Split-Transaktion mit Hauptbuchung und kategorisierten Teilbetraegen werden.

3. KI-Vorschlaege fuer Belegaufteilung
   Receipt-OCR und GPT/MCP sollen kuenftig pro Position oder Positionsgruppe eine Kategorie vorschlagen und dem Nutzer vor dem Speichern eine zusammengefasste Aufteilung zeigen.

4. Matching gegen Bankimport erhalten
   Auch bei aufgeteilten Belegen muss der spaetere Kontoauszugsimport Dubletten vermeiden. Dafuer braucht BudgetLove eine robuste Verknuepfung zwischen einem Ursprungsbeleg und mehreren Drafts oder Splits.

5. Multi-Tag-Filter mit gespeicherten View-Bookmarks
   Tag-Kombinationen fuer Sonderprojekte sollen als gespeicherte Ansichten verfuegbar sein.

6. Web-Push-Benachrichtigungen
   Budget-Limits, ungewoehnlich hohe Ausgaben und ueberfaellige Plaene sollen optional per Web Push gemeldet werden.

7. OCR-Ausbau fuer Belege
   Receipt-Uploads sollen optional serverseitige OCR nutzen, sobald `tesseract` oder ein anderer OCR-Dienst im passenden Container verfuegbar und betrieblich sauber konfiguriert ist.

## Cloud-Anbindung

BudgetLove soll keine eigene zentrale Cloud erzwingen. Gemeint ist eine optionale Verbindung zu Speicherzielen, die der Nutzer kontrolliert:

- Nextcloud/WebDAV fuer Belege, Exporte und Backups
- S3-kompatibler Speicher fuer verschluesselte Backups
- Manuelle Export-/Importfunktionen fuer Portabilitaet
- Spaeter optional automatisierte Backup-Jobs

Nicht fuer 1.0:

- Bank-Sync ueber PSD2
- zentrale Hosted-Cloud als Pflicht
- automatische Datenweitergabe an KI-Anbieter

## SQLite-Option

SQLite ist als zusaetzliche Betriebsart sinnvoll fuer:

- Single-User-Installationen
- lokale Tests
- einfache Demo-Setups
- Nutzer ohne Datenbankbetrieb

Offene technische Punkte:

- PostgreSQL-spezifische SQL-Syntax ersetzen oder abstrahieren
- Arrays und `array_agg`-Queries portieren
- Release-Migrations fuer beide Engines testen
- Locking/Concurrency fuer mehrere Nutzer bewerten
- Backup-/Restore-Verhalten dokumentieren

Entscheidung fuer 1.0:

- PostgreSQL bleibt Pflicht fuer Stable.
- SQLite wird als Roadmap-Punkt gefuehrt und erst nach einem dedizierten DB-Kompatibilitaets-Sprint aktiviert.

## Empfohlene Arbeitsreihenfolge

1. Onboarding und Closed-Beta-Fluss abschliessen.
2. End-to-End-Test Belegentwurf zu Bankimport durchfuehren.
3. Mobile Bottom-Sheet Quick-Add bauen.
4. Kann-ich-mir-das-leisten-Helfer bauen.
5. Trend-Sparklines, Recurring-Health und Payee-Mapping-CTA ergaenzen.
6. Public-Doku, Lizenz, CONTRIBUTING, CHANGELOG und Version fertigstellen.
7. Demo-Daten, Smoke-Tests und Release Notes vorbereiten.
