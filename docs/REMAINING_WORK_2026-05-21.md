# Offene Arbeitsschritte — Stand 2026-05-21

Aufgenommen nach Review der 32 unpushed Commits + Bug-Fix-Session. Stand wurde nach der Fortsetzung am 2026-05-21 aktualisiert: die drei kritischen Bugfixes und die direkten Folge-/Hygiene-Fixes sind committet.

Branch: `release` · Version: `0.30.11` · Ahead of origin/release: **39 Commits** nach diesem Doku-Sync. App-Working-Tree ist sauber; offen sind Landing-/Claude-Dokumentationsdateien.

---

## 0. Sofort / erledigt: Commit + Push

### 0.1 Drei Fix-Commits anlegen — erledigt

- **Commit A** — `fix: remove dead withdrawal code in saving-goals contributions API`
  - `public/api/saving-goals.php`
  - `VERSION` → 0.30.6
- **Commit B** — `fix: wrap transaction split conversion in DB transaction`
  - `public/api/transactions.php`
  - `public/transactions.php`
  - (Bonus mit drin: `transaction_splits.household_id` jetzt beim Insert gesetzt — war Issue 2.1 unten, ist damit erledigt)
  - `VERSION` → 0.30.7
- **Commit C** — `fix: cleanup orphan receipt attachments on group rollback`
  - `app/domain.php` (neuer Helper `hb_attachment_delete_binary`)
  - `public/open_bookings.php`
  - `VERSION` → 0.30.8

Zusätzlich erledigt:

- **Commit D** — `fix: harden receipts, saving goals and form handling`
  - `VERSION` → 0.30.9
  - Receipt-MIME-Allowlist zentral in `hb_attachment_store_binary`
  - Receipt-Split-Form von 5 auf 12 Zeilen erweitert
  - `transaction_splits.household_id` im Open-Bookings-Split-Flow gesetzt
  - Saving-Goals-API N+1 im Listing entfernt
  - Saving-Goals-PATCH auf eine Storage-SELECT reduziert
  - `$_POST['x'] !== ''`-Warnmuster bereinigt
  - Saving-Goals-Erfolgsmeldungen differenziert
  - Payee-Wildcard-Mappings nach Spezifizität sortiert
- **Commit E** — `docs: update BudgetLove agent workspace path`
  - `VERSION` → 0.30.10
  - veraltete `/srv/haushaltsbuch/repo`-Anweisung in `AGENTS.md` ersetzt
- **Commit F** — `docs: sync remaining work status`
  - `VERSION` → 0.30.11
  - diese Restarbeitsdatei auf den aktuellen Stand gebracht

### 0.2 Landing-Page-Änderungen entscheiden — offen
- `landing/index.html` und `landing/en/index.html` haben massive uncommittete Diffs (+1087/-456). Lesen, sich vergewissern dass es die finale Version ist, dann committen — oder verwerfen.
- `landing/v1/` und `landing/v2/` sind untracked, root:root-owned Agent-Snapshots. Optionen:
  - committen unter `landing/_drafts/` (oder ähnlich), wenn man sie als Versionsverlauf will
  - in `.gitignore` aufnehmen
  - löschen (`sudo rm -rf landing/v1 landing/v2`)

### 0.3 Push — offen
Aktuell 36 ahead von `origin/release`. Push ausführen, sobald Landing-Entscheidung und ggf. Docs/Claude-Dateien geklärt sind.

---

## 1. Mittel — erledigt mit `fix: harden receipts, saving goals and form handling`

### 1.1 Hardcoded 5-Split-Limit im Receipt-Form — erledigt
- **Datei:** `public/open_bookings.php`
- **Fix:** Limit auf 12 Zeilen erhöht.

### 1.2 MIME-Allowlist für Receipt-Upload — erledigt
- **Datei:** `app/domain.php`
- **Fix:** zentrale Allowlist in `hb_attachment_store_binary`, dadurch wirksam für `open_bookings.php`, `transactions.php` und API-Uploads.
- **Erlaubt:** JPEG, PNG, WebP, HEIC, HEIF, PDF.

### 1.3 N+1 in Saving-Goals-Listing — erledigt
- **Datei:** `public/api/saving-goals.php`
- **Fix:** gemeinsames `hb_saving_goal_format()` extrahiert; Listing lädt alle Spalten mit Joins in einer Query.

### 1.4 Drei statt eine fetchOne in Saving-Goals-PATCH — erledigt
- **Datei:** `public/api/saving-goals.php`
- **Fix:** eine kombinierte SELECT für `storage_type`, `source_account_id`, `storage_account_id`.

---

## 2. Niedrig / Hygiene

### 2.1 `transaction_splits.household_id` nicht beim INSERT gesetzt — erledigt
War in `public/api/transactions.php`, `public/transactions.php` und `public/open_bookings.php`. Alle bekannten Insert-Flows setzen jetzt `household_id`.

### 2.2 `$_POST['x'] !== '' ?` Pattern → PHP-8-Warnings — erledigt
- Bekannte Vorkommen wurden auf `($_POST['x'] ?? '') !== ''` umgestellt.
- Prüfkommando liefert keine Treffer mehr für `$_POST[...] !== ''` oder `$_POST[...] === ''`.

### 2.3 Generische „Saved."-Meldung in Saving-Goals — erledigt
- **Datei:** `public/saving_goals.php`
- **Fix:** `msg=created` und `msg=contribution_added` zeigen eigene Meldungen.

### 2.4 Wildcard-Payee-Mapping ohne Specificity-Ranking — erledigt
- **Datei:** `public/import.php`
- **Fix:** Wildcard-Patterns werden vor Verwendung nach Länge der Nicht-Wildcard-Anteile absteigend sortiert.

### 2.5 Saving-Goal-Drift bei Contribution-Delete
- **Status:** Aktuell noch nicht akut, weil es **kein** Delete-Endpoint für Contributions gibt. Aber sobald einer kommt, muss `saving_goals.current_amount_cents` zurückgezählt werden — entweder per Application-Code oder per Trigger.
- **Vorschlag:** Bei Implementierung eines Delete-Endpoints in `public/api/saving-goals.php`: DELETE-Branch hinzufügen, der innerhalb einer Tx Contribution löscht und `current_amount_cents -= amount_cents` updated.
- **Aufwand:** S (sobald die Feature angefragt wird)

---

## 3. Backlog-Features (von der Roadmap vom 2026-05-09, aktualisiert)

Status-aktualisiert nach Review:

### Hoher Hebel, kleiner Aufwand
- **#2 Trend-Sparkline** pro Kategorie/Tag/Empfänger (12-Monats-Mini-Chart neben Betrag). Identifiziert Ausreißer auf einen Blick. → reine Reports-Page-Erweiterung, kein DB-Schema-Change.

### Mobile UX
- **#4c Bottom-Sheet Quick-Add** mit Smart-Defaults „Betrag → letzte Kategorie/Empfänger → Konto". Heute nur Deep-Link. → JS-Modal-Variante, kein neuer Endpoint.
- **#5 Beleg-Foto** an Transaktion: ist über Receipt-Upload-Flow weitgehend da. Offen bleibt **OCR via tesseract** im `hb_ws`-Container. → siehe auch `webcam-ocr-scanner-prototype` (anderes Repo) als Referenz.

### Datenqualität / Pflege
- **#6 Recurring-Health-Score**: prüfe ob letzte 2–3 erwartete Termine in transactions gematcht wurden, sonst „möglicherweise nicht mehr aktiv". → Query auf `recurring_rules` + `transactions`-Match-Logik.
- **#8 Payee-Mapping aus Reports-Unassigned** direkt anlegen: Wildcard-Mapping ist da (siehe `payee_mapping.php`), aber kein „Quick-Create aus Reports-Bucket"-CTA. → Button im Reports-Unassigned-Bucket, der einen Mapping-Modal vorbefüllt.

### Strategisch
- **#9 Forecast-Slider 3/6/12 Monate** (Liquiditäts-Projektion „kann ich im November Urlaub?") → erweitert `domain.php` Forecast-Helper + UI-Slider.
- **#10 Multi-Tag-Filter mit gespeicherten View-Bookmarks** (Tag-Kombinationen für Sonderprojekte) → Schema-Erweiterung (`saved_views`-Tabelle?), UI-Komponente.
- **#11 Web-Push Benachrichtigungen** (Budget-Limit, ungewöhnlich hohe Ausgabe, Plan überfällig) → Service-Worker + VAPID-Keys + Subscription-Endpoint.

---

## 4. Strukturelles / Außerhalb des App-Repos

### 4.1 Caddy-Split entscheiden
Routes für `cloud.`, `dns.`, `git.`, `code-*.`, `retrogaming.budgetlove.de` waren historisch im BL-Repo, sind aktuell raus, aber der **dauerhafte Zielort** ist noch offen. Drei Optionen:
- Eigenes Infra-Repo (z. B. `~/projects/budgetlove-infra` oder `/opt/deploy/server-infra-bootstrap`)
- Separater Pfad/Branch im selben Repo (`infra/` mit eigener `.gitignore`-Strategie)
- Außerhalb von Git pflegen (z. B. nur in `/opt/deploy/…`)

Sobald entschieden: aus `~/.claude/projects/-/memory/project_reminder_caddy_split.md` und dem entsprechenden MEMORY-Index-Eintrag den Reminder löschen.

### 4.2 Cloud-SQLite-QA-Pass
Nach all den Härtungs-Fixes der letzten 14+ Commits einmal manuell den ganzen Cycle durchspielen:
1. Frischer Cloud-Login → SQLite-Snapshot wird gezogen
2. Lokale Bearbeitung (Transaction anlegen/editieren, Split, Receipt-Upload)
3. Session-Takeover-Lease (zweites Device)
4. Empty-Snapshot-Recovery (provoziert via leerer SQLite-File)
5. CAMT-Import mit Duplikaten + macOS-Zip + Empty-File

Checkliste vorhanden: `docs/CLOUD_RUNTIME_HARDENING_CHECKLIST.md`.

### 4.3 QA-API-Splits-Saving-Goals
Checkliste vorhanden: `docs/QA_API_SPLITS_SAVING_GOALS.md`. Vor 0.31.0 oder vor breitem Release durchgehen.

---

## Aufwand-Zusammenfassung

| Bucket | Items | Geschätzt |
|---|---|---|
| 0 Sofort (Commit+Push) | 3 | 15–30 Min |
| 1 Mittel | 4 | 1–2 h |
| 2 Niedrig/Hygiene | 5 | 1–2 h |
| 3 Backlog | 7 | je 0,5–2 d |
| 4 Strukturell | 3 | je nach Entscheidung |

**Empfohlene Reihenfolge:** 0 → 1.1/1.2 (sicherheits- und UX-nah) → 2.2 ($_POST-Pattern, weil großflächig vor neuen Features) → dann Backlog.
