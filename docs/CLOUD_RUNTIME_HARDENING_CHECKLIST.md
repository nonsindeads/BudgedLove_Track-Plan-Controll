# Cloud Runtime Hardening Checklist

Statusdatum: 2026-05-18

Scope: Household cloud mode with ephemeral SQLite runtime session.

## Required checks

1. Login fail-closed
- If cloud session start fails, login must stop with explicit error.
- No silent fallback to household PostgreSQL runtime data.

2. Session start integrity
- Reject missing remote object.
- Reject decrypt failure.
- Reject non-SQLite payloads.

3. Session stop integrity
- Do not upload empty sqlite file.
- Do not upload invalid sqlite header file.

4. Session cleanup
- GC removes stale `/tmp/budgetlove-sessions/*` paths.
- Active sessions are not deleted.

5. Secret handling
- Cloud access secret and encryption key remain separated in roadmap.
- No plaintext secret logging.

6. Operational observability
- Dedicated audit log events for:
  - session_start_success
  - session_start_failed
  - session_stop_upload_success
  - session_stop_upload_failed
  - session_gc_delete

7. Restore drill
- Perform one full drill:
  - migrate -> logout -> login -> modify data -> logout -> login -> verify persisted state from cloud sqlite artifact.

