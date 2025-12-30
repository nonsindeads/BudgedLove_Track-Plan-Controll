# Example Environment Variables

- `HB_DB_DSN`: e.g. `pgsql:host=hb_db;port=5432;dbname=haushaltsbuch`
- `HB_DB_USER`: DB user
- `HB_DB_PASS`: DB password
- `HB_UPLOAD_DIR`: attachment storage path (default `/srv/haushaltsbuch/uploads`)
- `APP_BASE_URL`: optional, for absolute links
- `HB_WS_URL`: WebSocket URL for live feed/chat (e.g. `ws://localhost:8081`)
- `HB_WS_SECRET`: secret for WS token signing
- `HB_WS_BIND`: bind address for WS server (e.g. `websocket://0.0.0.0:8081`)
