# Beispiel-Umgebungsvariablen

- `HB_DB_DSN`: z.B. `pgsql:host=hb_db;port=5432;dbname=haushaltsbuch`
- `HB_DB_USER`: DB-User
- `HB_DB_PASS`: DB-Passwort
- `HB_UPLOAD_DIR`: Pfad für Anhänge (Default `/srv/haushaltsbuch/uploads`)
- `APP_BASE_URL`: optional für absolute Links
- `HB_WS_URL`: WebSocket-URL für Live-Log/Chat (z.B. `ws://localhost:8081`)
- `HB_WS_SECRET`: Secret für WS-Token-Signierung
- `HB_WS_BIND`: Bind-Adresse für den WS-Server (z.B. `websocket://0.0.0.0:8081`)
