# Nextcloud Cloud Mode Setup (BudgetLove)

This setup keeps `data_residency_mode=cloud` and uses Nextcloud WebDAV for snapshots and session workflows.

Important runtime status:
- Self-hosted/server mode is unchanged and continues to use PostgreSQL.
- Nextcloud cloud mode has fail-closed session handling for encrypted SQLite files.
- Doctrine DBAL is available as the portability layer for PostgreSQL/SQLite.
- Runtime page/API queries have been ported away from the known PostgreSQL-only constructs (`RETURNING`, `ILIKE`, runtime `array_agg`, inline `now()` updates) and are ready for SQLite session testing.
- The app must not silently fall back to PostgreSQL for a cloud household when the encrypted SQLite session cannot be opened.

## 1) Household Settings

Open `Household -> Settings` and set:

- `Data residency`: `Cloud`
- `Cloud provider`: `Nextcloud`
- `Cloud sync mode`: `SQLite snapshots` (or your preferred mode)
- `Cloud user identifier`: Nextcloud username (for example `cloudadmin`)
- `Cloud remote path`: relative folder path in that user space (for example `BudgetLove/Household-1`)
- `Cloud endpoint URL (Nextcloud WebDAV)`:  
  `https://cloud.budgetlove.de/remote.php/dav/files/<USERNAME>`
- `Cloud app password / token`: Nextcloud app password
- `Session TTL`: as required (for example `120`)
- `Ephemeral local cache only`: enabled for `/tmp` session usage

Save settings.

## 2) Built-in Validation

In `Household -> Cloud snapshots`:

- Run `Test Nextcloud connection`
  - creates a temporary test folder below `cloud_remote_path`
  - writes and reads a test file via WebDAV
- Run `Create cloud snapshot now`
  - creates `budgetlove-snapshot-household-<id>-<timestamp>.json.gz`
  - uploads `.json.gz` and `.sha256`
  - if sync mode is `Receipts and exports`, receipt files are also uploaded
- Run `Migrate local data to Nextcloud`
  - pre-checks that Nextcloud config is complete
  - runs connection test
  - creates and uploads a migration snapshot
  - creates and uploads encrypted `session-db/household-<id>.sqlite.enc`
  - does not copy `users`, API tokens, OAuth tokens or the Nextcloud app password into the SQLite export
  - uploads receipt files from `attachments.storage_path`
  - shows snapshot target in success message

## 3) Session SQLite Scripts (prototype path)

Available scripts:

- `tools/cloud/sqlite-session-start.sh`
- `tools/cloud/sqlite-session-stop.sh`
- `tools/cloud/sqlite-session-gc.sh`

Safety behavior:

- Start fails if the remote encrypted SQLite file cannot be downloaded.
- Start fails if the encrypted file cannot be decrypted.
- Empty SQLite initialization is only allowed when `ALLOW_INIT_EMPTY=1` is passed explicitly.
- Stop refuses to upload empty files.
- Stop refuses to upload files that do not start with a SQLite file header.
- Login shows a cloud-session error instead of continuing with server data if a cloud household requires ephemeral SQLite and the session cannot start.

Recommended GC schedule:

```cron
*/5 * * * * <repo>/tools/cloud/sqlite-session-gc.sh --root /tmp/budgetlove-sessions --ttl-minutes 30
```

## 4) Notes

- Self-hosted users can keep `Data residency = Server` (local DB workflow unchanged).
- Nextcloud credentials should be app-password based, not primary account password.
- For production hardening, move cloud secrets to encrypted storage instead of plain DB fields.
- Cloud access secret and SQLite encryption key should be separated before public release.
- Before enabling cloud SQLite for more households, run one full E2E cycle: migrate, log out, log in, create/edit a transaction, upload a receipt, log out, log in again, verify the data persists from the encrypted Nextcloud SQLite file.
