# Cron Setup

The `cron.php` script processes due `recurring_rules` and creates transactions or tasks.

Example cronjob (every 5 minutes):
```
*/5 * * * * /usr/bin/php /srv/haushaltsbuch/repo/cron.php
```

Notes:
- The script needs the same environment variables as the app (`HB_DB_DSN`, `HB_DB_USER`, `HB_DB_PASS`, optional `HB_UPLOAD_DIR`).
- `SELECT ... FOR UPDATE SKIP LOCKED` ensures parallel cron runs do not process the same rule twice.
- `next_run_at` is advanced after each run; `recurring_executions` logs the execution time.
