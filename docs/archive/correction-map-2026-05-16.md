# BudgetLove Correction Map 2026-05-16

Review-Scope:
- Commits seit `origin/release` vom 2026-05-15/2026-05-16
- aktueller Working Tree am 2026-05-16
- Roadmaps: `docs/product/roadmap-1.0.md`, `docs/api/public-api-roadmap.md`, Cloud-/SQLite-Plan, Saving-Goals-Plan
- Fokus: Plausibilitaet, Datenintegritaet, Backward Compatibility, Sicherheit

## Sofort korrigiert

### P0-FIX-001: Upload-Verzeichnis nicht schreibbar

Symptom:
- `mkdir(): Permission denied`
- `Upload-Verzeichnis konnte nicht erstellt werden: /srv/haushaltsbuch/uploads/4`

Ursache:
- Bind-Mount `/srv/haushaltsbuch/uploads` gehoerte nicht dem PHP-FPM-User `www-data` im Container.

Korrektur:
- Host-Verzeichnis auf uid/gid `82:82` gesetzt.
- Schreibtest als `www-data` im Container erfolgreich.

Nacharbeit:
- Deployment-Runbook ergaenzen: Upload-Verzeichnis muss fuer Container-User `www-data` schreibbar sein.

### P0-FIX-002: Split-Gruppen wurden bei Buchung nicht zahlungswirksam

Risiko:
- `transaction_groups.status=booked` ohne passenden Eintrag in `transactions` waere in Kontostand, Dashboard und Reports unsichtbar.

Korrektur:
- API `POST /api/transaction-groups.php` erzeugt bei `status=booked` eine Header-Transaktion.
- Bankimport-Match erzeugt beim Match gegen Split-Gruppe eine Header-Transaktion.
- `transaction_splits.transaction_id` wird auf diese Header-Transaktion gesetzt.
- `transaction_groups.matched_transaction_id` wird gesetzt.

Nacharbeit:
- DELETE/Archivierung gebuchter Gruppen fachlich klaeren: Archivieren allein storniert die Header-Transaktion aktuell nicht.

### P0-FIX-003: Cloud-Snapshots deckten neue Split-Belege nicht vollstaendig ab

Risiko:
- `receipts`, `transaction_groups` und gruppenbasierte `transaction_splits` waeren im Snapshot/Nextcloud-Migration nicht vollstaendig enthalten.

Korrektur:
- Snapshot-Table-Map um `receipts` und `transaction_groups` erweitert.
- `transaction_splits` werden nun ueber `transaction_id` und `transaction_group_id` eingesammelt.

Nacharbeit:
- Restore-Pfad fuer diese Tabellen fehlt noch.

### P1-FIX-004: Schema-Altbestand `transaction_splits.updated_at`

Symptom:
- API-Formatierung brach mit `column ts.updated_at does not exist`.

Korrektur:
- Release `0.30.4` mit Migration `001_transaction_splits_updated_at.sql`.
- Migration auf laufender DB angewendet.

## Offene Korrekturen

### P0-001: Cloud-SQLite ist noch kein echter Runtime-Storage

Ist-Zustand:
- Login startet `sqlite-session-start.sh` und legt eine entschluesselte SQLite-Datei unter `/tmp` an.
- Die App nutzt aber weiter `HB_DB_DSN`/PostgreSQL ueber `hb_get_pdo()`.

Risiko:
- UI kann suggerieren, dass Finanzdaten nicht mehr serverseitig liegen, obwohl die produktiven Daten weiter in PostgreSQL bleiben.

Korrekturpfad:
- Doctrine DBAL als neuen DB-Abstraktionslayer nutzen.
- Neue/portierte Datenpfade ueber `hb_dbal_household()` schreiben.
- Kritische Household-Pfade zuerst portieren: Accounts, Kategorien, Payees, Tags, Transactions, Planned Payments, Receipts/Splits.
- Danach Cloud-SQLite als Runtime aktivieren.

Aktueller Fortschritt:
- `app/dbal.php` eingefuehrt.
- `hb_dbal_server()` liefert PostgreSQL-Connection.
- `hb_dbal_household()` liefert bei aktiver Cloud-Session SQLite, sonst PostgreSQL.
- Nextcloud-Migration erzeugt zusaetzlich eine verschluesselte `session-db/household-<id>.sqlite.enc`.
- Laufende Seiten nutzen grossflaechig noch PDO/PostgreSQL und muessen schrittweise portiert werden.

### P0-002: Cloud-Secret wird plaintext gespeichert und als SQLite-Key wiederverwendet

Ist-Zustand:
- `households.cloud_access_secret` enthaelt Nextcloud-App-Passwort.
- Dasselbe Secret wird als `SQLITE_KEY` genutzt.

Risiko:
- Server-Admin oder DB-Leak kann Cloud-Zugriff und SQLite-Entschluesselung bekommen.
- Schluesseltrennung fehlt.

Korrekturpfad:
- Cloud-Zugang und Verschluesselungs-Key trennen.
- Serverseitig mindestens envelope encryption fuer Secrets.
- Optional spaeter client-/user-passphrase-basierte Entschluesselung fuer echten Zero-Knowledge-Modus.

### P0-003: SQLite-Startskript erstellt bei Download-/Decrypt-Fehler eine leere DB

Status:
- Korrigiert am 2026-05-17.

Korrektur:
- `sqlite-session-start.sh` bricht bei fehlendem Remote-Objekt oder Decrypt-Fehler ab.
- Leere Initialisierung ist nur noch explizit mit `ALLOW_INIT_EMPTY=1` moeglich.
- `sqlite-session-stop.sh` verweigert Uploads von leeren oder nicht als SQLite erkennbaren Dateien.
- Login startet Cloud-Haushalte fail-closed: kein stiller Fallback auf PostgreSQL, wenn die Cloud-Session nicht geoeffnet werden kann.

Nacharbeit:
- Remote-Backup/Versionierung vor jedem Upload ergaenzen.
- Expliziten Admin-Bootstrap fuer den ersten Cloud-SQLite-Stand bauen.

### P1-001: DELETE fuer gebuchte Split-Gruppen ist fachlich unvollstaendig

Ist-Zustand:
- `DELETE /api/transaction-groups.php` setzt `status=archived`.
- Falls bereits eine Header-Transaktion existiert, bleibt diese bestehen.

Risiko:
- User/GPT glaubt, die Gruppe sei entfernt; Finanzwirkung bleibt aber im Konto.

Korrekturpfad:
- Entweder DELETE nur fuer Draft-Gruppen erlauben.
- Oder gebuchte Gruppen mit explizitem `reversal=true` rueckgaengig machen.
- OpenAPI und GPT-Wissen entsprechend scharf formulieren.

### P1-002: Idempotency bei Multipart-Receipt-Uploads ist nicht payload-sicher

Ist-Zustand:
- `php://input` ist bei `multipart/form-data` leer oder nicht zuverlaessig nutzbar.
- Der Idempotency-Hash deckt dann die Datei nicht sauber ab.

Risiko:
- Gleicher `Idempotency-Key` mit anderer Datei kann falsch als identisch behandelt werden.

Korrekturpfad:
- Bei Multipart den Hash aus `REQUEST_METHOD`, URI, Formfeldern und hochgeladenem Datei-Hash bilden.
- Conflict `409` bei gleicher Idempotency-Key/anderer Datei.

### P1-003: Split-Summenregel ist nur API-validiert

Ist-Zustand:
- `split_total_mismatch` wird in `/api/transaction-groups.php` korrekt geprueft.
- Es gibt keine DB-Constraint/Trigger fuer `transaction_groups.total_amount_cents = sum(transaction_splits.amount_cents)`.

Risiko:
- Direkte DB-Skripte, Admin-Tools oder spaetere UI-Pfade koennen inkonsistente Gruppen erzeugen.

Korrekturpfad:
- Deferred Constraint Trigger fuer Insert/Update/Delete auf `transaction_splits`.
- Alternativ zentrale Domain-Funktion fuer alle Schreibpfade und Tests gegen direkte Inkonsistenzen.

### P1-004: Restore fuer Cloud-Snapshots fehlt

Ist-Zustand:
- Nextcloud-Migration erzeugt Snapshot und Belegkopien.
- Restore/Import dieses Snapshots ist nicht implementiert.

Risiko:
- Migration wirkt wie ein vollstaendiger Umzug, ist aber aktuell Backup/Export.

Korrekturpfad:
- UI-Wording anpassen: "Snapshot exportieren" statt "Migration", bis Restore existiert.
- Restore-Tool bauen und gegen Test-Haushalt pruefen.

### P2-001: Zweites OpenAPI-Dokument ist veraltet

Ist-Zustand:
- `docs/api/customgpt-openapi.yaml` ist aktuell.
- `docs/api/openapi.yaml` ist ein alter, kleiner Stand.

Risiko:
- Falsches Schema kann versehentlich im GPT Builder oder GitHub referenziert werden.

Korrekturpfad:
- Entweder entfernen/archivieren oder automatisch auf `customgpt-openapi.yaml` verweisen.
- README/API-Doku auf eine einzige kanonische Schema-Quelle reduzieren.

### P2-002: Reports/Dashboard muessen Split-Gruppen bewusst behandeln

Ist-Zustand:
- Durch Header-Transaktion + `transaction_splits.transaction_id` sind Reports grundsaetzlich kompatibel.
- Neue Gruppenmetadaten wie `receipt_id`, `transaction_group_id` und `matched_transaction_id` werden UI-seitig noch nicht gezielt angezeigt.

Korrekturpfad:
- Transaktionsdetail: "Teil einer Split-Gruppe" anzeigen.
- Belegdetail-Seite bauen.
- Reports mit Receipt-/Group-Link anreichern.

## Tests bisher

- PHP-Lint sauber:
  - `app/api.php`
  - `public/api/receipts.php`
  - `public/api/transaction-groups.php`
  - `public/api/transactions.php`
  - `public/api/transaction_drafts.php`
  - `public/import.php`
  - `public/household.php`
- OpenAPI YAML parsebar: Version `0.30.4`, 17 Pfade, 55 Schemas.
- API-E2E manuell:
  - Receipt-Metadaten erstellt.
  - Split-Gruppe mit korrekter Summe erstellt.
  - Falsche Split-Summe liefert `split_total_mismatch`.
  - Patch funktioniert.
  - Archivieren funktioniert.
  - `status=booked` erzeugt Header-Transaktion und verknuepfte Splits.
- Migrationen angewendet:
  - `0.30.3/001_receipts_transaction_groups.sql`
  - `0.30.4/001_transaction_splits_updated_at.sql`

## Empfohlene Reihenfolge

1. P0-001 abschliessen, bevor Cloud-Modus als "keine Serverdaten" technisch aktiviert wird.
2. P1-001 und P1-002 vor produktiver GPT-Nutzung mit echten Belegen.
3. P1-003 vor Public Beta.
4. P1-004 vor echter Migration von lokalen Daten.
5. P2-001/P2-002 vor GitHub-Release 1.0.
