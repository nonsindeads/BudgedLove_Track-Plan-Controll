# Cron Setup

Das Skript `cron.php` verarbeitet fällige `recurring_rules` und erzeugt daraus Transaktionen oder Tasks.

Beispiel-Cronjob (alle 5 Minuten):
```
*/5 * * * * /usr/bin/php /srv/haushaltsbuch/repo/cron.php
```

Hinweise:
- Skript benötigt dieselben Umgebungsvariablen wie die App (`HB_DB_DSN`, `HB_DB_USER`, `HB_DB_PASS`, optional `HB_UPLOAD_DIR`).
- Nutzung von `SELECT ... FOR UPDATE SKIP LOCKED` stellt sicher, dass parallele Cron-Läufe dieselbe Regel nicht doppelt verarbeiten.
- `next_run_at` wird pro Regel nach Ausführung fortgeschrieben, `recurring_executions` loggt den Laufzeitpunkt.
