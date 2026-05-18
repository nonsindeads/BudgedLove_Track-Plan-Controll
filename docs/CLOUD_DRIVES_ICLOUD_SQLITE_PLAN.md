# BudgetLove Cloud Drives Plan (iCloud-first)

Stand: 2026-05-17

## Zielbild

Cloud Drives sollen nicht nur Belegablage sein, sondern auch Daten-Portabilitaet fuer eine spaetere SQLite-Betriebsart ermoeglichen.

Startprovider:
- iCloud Drive (primaer)

Danach:
- Nextcloud
- Gmail (nur fuer Export/Backup-Pakete, nicht als Dateisystem)

## Produktgrenzen

- Kein Multi-Writer fuer eine aktive SQLite-Datei ueber Cloud-Sync.
- Keine gleichzeitige Live-Nutzung derselben SQLite-Datei von mehreren Geraeten.
- Cloud dient fuer Export/Backup/Restore und optional fuer kontrollierten Device-Wechsel.
- Self-hosted lokale Datenbank bleibt ein voll unterstuetzter Standardpfad.

## Architekturprinzip

1. Betriebsarten trennen:
- `postgres` (Default, serverseitig, produktiv)
- `sqlite` (spaeter, single-user fokussiert)

2. Cloud Storage als Adapter:
- `cloud_provider`: `icloud`, `nextcloud`, `gmail`
- Einheitliche Operationen:
  - `put_object`
  - `get_object`
  - `list_objects`
  - `delete_object` (optional)
  - `health_check`

3. Backup-Objekttypen:
- `receipts/<household>/<yyyy>/<mm>/...`
- `exports/<household>/budgetlove-export-<timestamp>.zip`
- `sqlite/<household>/snapshot-<timestamp>.sqlite3.zst`

## iCloud-first Umsetzungsweg

Wichtig:
- iCloud hat keine stabile, frei dokumentierte Server-API wie S3/WebDAV fuer headless Server.
- Praktisch sinnvoll ist ein lokaler/Client-seitiger iCloud-Sync-Ordner (Apple-ID Session auf Mac), nicht ein reiner VPS-Direktzugriff.

Empfohlener Start:
1. BudgetLove erzeugt Export-/Backup-Dateien lokal auf dem Host (`/srv/budgetlove/exports`).
2. Auf dem Apple-Geraet synchronisiert ein iCloud-Ordner diese Dateien.
3. Optionaler Agent (spaeter): signierter Upload-Client auf Mac, der Dateien aus dem Export-Ordner uebernimmt.

## SQLite-spezifische Regeln

Wenn SQLite spaeter aktiviert wird:
- Nur Snapshot-Backups hochladen, nie die gerade geoeffnete Live-Datei.
- Snapshot-Erzeugung atomar:
  - DB lock kurz halten
  - konsistenten Dump/Snapshot erstellen
  - Datei komprimieren und signieren (sha256 manifest)
- Restore nur in Wartungsmodus.
- Konflikterkennung ueber Snapshot-Metadaten:
  - `created_at`
  - `device_id`
  - `db_schema_version`
  - `sha256`

## Session-Modell (dein Ansatz)

Dieses Modell ist fuer "keine dauerhaften Finanzdaten lokal auf dem Server" geeignet:

1. Login:
- verschluesselte SQLite aus Cloud laden
- lokal nur in `/tmp/budgetlove-sessions/<session_id>/db.sqlite` entschluesseln

2. Laufzeit:
- App arbeitet nur auf Session-SQLite
- `LAST_TOUCH` in Metadatei aktualisieren

3. Logout / Session-Ende:
- SQLite verschluesseln
- zurueck in Cloud schreiben
- lokale Session-Dateien sofort loeschen

4. Sicherheitsnetz:
- Cron alle 5 Minuten
- stale Session-Ordner in `/tmp` sicher entfernen

Vorbereitete Skripte:
- `tools/cloud/sqlite-session-start.sh`
- `tools/cloud/sqlite-session-stop.sh`
- `tools/cloud/sqlite-session-gc.sh`

Beispiel-Cron:
```cron
*/5 * * * * /root/projects/BudgedLove_Track-Plan-Controll/tools/cloud/sqlite-session-gc.sh --root /tmp/budgetlove-sessions --ttl-minutes 30
```

## Sicherheitsanforderungen

- Verschluesselung vor Cloud-Upload (mindestens AES-256, passphrase- oder key-basiert).
- Kein Speichern von Apple-Zugangsdaten in BudgetLove.
- Klare Trennung:
  - App-Secrets
  - Cloud-Zugang
  - Verschluesselungs-Key

## Datenmodell (Vorbereitung)

Neue Tabellen (Plan):
- `cloud_connections`
  - `id`
  - `household_id`
  - `provider` (`icloud|nextcloud|gmail`)
  - `mode` (`receipts|exports|sqlite_snapshots|all`)
  - `is_active`
  - `config_json` (provider-spezifisch, ohne Roh-Secrets)
  - `created_at`
  - `updated_at`

- `cloud_sync_jobs`
  - `id`
  - `household_id`
  - `connection_id`
  - `job_type` (`export_push|receipt_push|sqlite_snapshot_push|restore_pull`)
  - `status` (`pending|running|done|failed`)
  - `artifact_path`
  - `artifact_hash`
  - `error_message`
  - `created_at`
  - `started_at`
  - `finished_at`

## Roadmap (konkret)

Phase A (jetzt):
- Dokumentation und Betriebsgrenzen finalisieren.
- E2E-Test fuer Belegentwurf/Import abschliessen (ohne Cloud).

Phase B (iCloud MVP):
- Export-/Backup-Artefakte standardisieren (`zip` + `manifest.json` + `sha256`).
- Zielordner-Konzept fuer iCloud-Sync definieren.
- Manueller Push/Pull-Prozess dokumentieren.
- Hilfsskript vorhanden:
  - `tools/cloud/build-cloud-bundle.sh --source-dir <dir> --out-dir <dir>`
  - erzeugt `payload.tar.gz`, `manifest.json` und `payload.tar.gz.sha256`.

Phase C (Provider-Abstraktion):
- Interne Storage-Adapter-Schnittstelle einfuehren.
- Nextcloud-Adapter (WebDAV) als erster vollautomatischer Server-Adapter.
- Gmail-Adapter nur fuer versendete Backup-Artefakte.

Phase D (SQLite snapshots):
- SQLite Snapshot-Pipeline bauen.
- Verschluesselte Snapshot-Uploads.
- Restore-Workflow mit Integritaetspruefung.

Phase E (direkter Cloud-Runtime-Storage):
- Doctrine DBAL als Storage-Adapter fuer PostgreSQL und SQLite nutzen.
- PostgreSQL-spezifische Queries portabel machen (`returning`, `ilike`, Casts, `set_config`, JSONB, Aggregationen).
- Household-Daten beim Login ausschliesslich aus der entschluesselten Session-SQLite lesen.
- Login/Auth/Benutzerfreischaltung bleiben serverseitig getrennt, damit Accounts weiterhin funktionieren.
- Bei Cloud-Session-Fehlern fail-closed abbrechen, kein Fallback auf PostgreSQL-Haushaltsdaten.
- Erst danach Cloud-Modus als echte "keine Finanzdaten dauerhaft auf dem Server"-Betriebsart aktivieren.

Aktueller technischer Schutz:
- Session-Start erzeugt keine leere DB mehr bei Download-/Decrypt-Fehlern.
- Session-Stop laedt keine leere oder offensichtlich ungueltige SQLite-Datei hoch.
- Login bricht sichtbar ab, wenn eine verpflichtende Cloud-SQLite-Session nicht gestartet werden kann.
- DBAL-Basis ist vorhanden (`app/dbal.php`) und wurde gegen PostgreSQL und SQLite getestet.
- Die bekannten PostgreSQL-only Runtime-Stellen wurden auf DBAL/portable SQL umgestellt.
- Nextcloud-Migration erzeugt neben dem JSON-Snapshot eine verschluesselte SQLite-Runtime-Datei unter `session-db/household-<id>.sqlite.enc`.
- Auth-/Freischaltungsdaten bleiben serverseitig: `users`, API-Tokens, OAuth-Tokens und Nextcloud-App-Passwort werden nicht in den SQLite-Export kopiert.

## Entscheidung fuer den naechsten Sprint

Empfohlen:
1. Nextcloud-Migration fuer Haushalt `Glashauser` im UI ausfuehren.
2. Danach Login-/Logout-Zyklus mit verpflichtender ephemerer SQLite-Session testen.
3. Beleg-/Import-E2E final gruen bekommen.
4. Danach iCloud-Sync-Runbook fuer Export-Artefakte ausarbeiten.
