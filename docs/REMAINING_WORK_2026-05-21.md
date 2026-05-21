# Abgearbeitete Restliste — Stand 2026-05-21

Aufgenommen nach Review der 32 unpushed Commits + Bug-Fix-Session. Diese Datei ist nach der Abarbeitung kein Backlog mehr, sondern ein Abschlussprotokoll.

Branch: `release` · Version: `0.30.15`.

---

## 0. Sofort

### 0.1 Fix-Commits — erledigt

- `fix: remove dead withdrawal code in saving-goals contributions API` → 0.30.6
- `fix: wrap transaction split conversion in DB transaction` → 0.30.7
- `fix: cleanup orphan receipt attachments on group rollback` → 0.30.8

### 0.2 Folge-/Hygiene-Fixes — erledigt

- `fix: harden receipts, saving goals and form handling` → 0.30.9
- `docs: update BudgetLove agent workspace path` → 0.30.10
- `docs: sync remaining work status` → 0.30.11
- `feat: delete saving goal contributions safely` → 0.30.12
- `feat: refresh public landing pages` → 0.30.13
- `docs: add Claude quick reference` → 0.30.14
- `docs: move remaining roadmap items to roadmap` → 0.30.15

### 0.3 Landing-Page-Entscheidung — erledigt

- `landing/index.html` und `landing/en/index.html` wurden als fertige Public-Landing-Überarbeitung committet.
- `landing/v1/` und `landing/v2/` wurden als lokale Draft-/Snapshot-Artefakte in `.gitignore` aufgenommen.
- `CLAUDE.md` wurde als Quick-Ref committet.

---

## 1. Code-Fixes

Erledigt:

- Receipt-Split-Form von 5 auf 12 Zeilen erweitert.
- Zentrale MIME-Allowlist für Attachments in `hb_attachment_store_binary`.
- Saving-Goals-Listing ohne N+1.
- Saving-Goals-PATCH ohne dreifache `fetchOne`.
- `transaction_splits.household_id` in allen bekannten Insert-Flows gesetzt.
- `$_POST['x'] !== ''`-Warnmuster bereinigt.
- Saving-Goals-Meldungen differenziert.
- Wildcard-Payee-Mappings nach Spezifität sortiert.
- Contribution-Delete für Saving Goals implementiert: `DELETE /api/saving-goals.php?resource=contributions&id=<ID>`.

Validierung:

- Lokaler `php -l` für geänderte PHP-Dateien sauber.
- Container-`php -l` für geänderte PHP-Dateien sauber.
- `$_POST[...] !== ''` / `$_POST[...] === ''`-Prüfung ohne Treffer.

---

## 2. Roadmap- und Strukturpunkte

Erledigt:

- Backlog-Features aus dieser Restliste wurden in `docs/ROADMAP_1_0.md` einsortiert.
- API-QA für Contribution-Delete wurde in `docs/QA_API_SPLITS_SAVING_GOALS.md` ergänzt.
- CustomGPT Planning Actions wurden um Saving-Goal-Contribution-Delete erweitert.
- Caddy-/Infra-Split wurde entschieden und in `docs/INFRA_SPLIT_DECISION.md` dokumentiert.

---

## 3. Eingeordnete Roadmap-Punkte

Diese Punkte sind nicht mehr in dieser Restarbeitsdatei offen, sondern in `docs/ROADMAP_1_0.md` geführt:

- Trend-Sparkline pro Kategorie/Tag/Empfänger.
- Bottom-Sheet Quick-Add.
- OCR-Ausbau für Belege.
- Recurring-Health-Score.
- Payee-Mapping aus Reports-Unassigned.
- Forecast-/Kann-ich-mir-das-leisten-Helfer.
- Multi-Tag-Filter mit gespeicherten View-Bookmarks.
- Web-Push-Benachrichtigungen.
- Cloud-SQLite-QA-Pass.
- API-QA für Splits und Saving Goals.

---

## 4. Abschluss

Alle ursprünglich in dieser Datei geführten direkten Arbeitspunkte sind erledigt, committet oder in die dauerhafte Roadmap überführt.
