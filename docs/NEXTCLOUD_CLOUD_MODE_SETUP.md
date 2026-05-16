# Nextcloud Cloud Mode Setup (BudgetLove)

This setup keeps `data_residency_mode=cloud` and uses Nextcloud WebDAV for snapshots and session workflows.

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

## 3) Session SQLite Scripts (prototype path)

Available scripts:

- `tools/cloud/sqlite-session-start.sh`
- `tools/cloud/sqlite-session-stop.sh`
- `tools/cloud/sqlite-session-gc.sh`

Recommended GC schedule:

```cron
*/5 * * * * /root/projects/BudgedLove_Track-Plan-Controll/tools/cloud/sqlite-session-gc.sh --root /tmp/budgetlove-sessions --ttl-minutes 30
```

## 4) Notes

- Self-hosted users can keep `Data residency = Server` (local DB workflow unchanged).
- Nextcloud credentials should be app-password based, not primary account password.
- For production hardening, move cloud secrets to encrypted storage instead of plain DB fields.
