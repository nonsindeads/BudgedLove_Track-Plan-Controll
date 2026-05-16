#!/usr/bin/env bash
set -euo pipefail

# Encrypts session SQLite from /tmp and uploads it to Nextcloud WebDAV.
#
# Required env:
#   NC_WEBDAV_BASE
#   NC_USER
#   NC_PASS
#   SQLITE_REMOTE
#   SQLITE_KEY
#   SESSION_ID
# Optional:
#   SESSION_ROOT     default /tmp/budgetlove-sessions
#   KEEP_LOCAL       1 to keep decrypted sqlite for debugging

for v in NC_WEBDAV_BASE NC_USER NC_PASS SQLITE_REMOTE SQLITE_KEY SESSION_ID; do
  if [[ -z "${!v:-}" ]]; then
    echo "ERROR: missing env $v" >&2
    exit 1
  fi
done

SESSION_ROOT="${SESSION_ROOT:-/tmp/budgetlove-sessions}"
SESSION_DIR="${SESSION_ROOT%/}/${SESSION_ID}"
DB_FILE="${SESSION_DIR}/db.sqlite"
ENC_FILE="${SESSION_DIR}/db.sqlite.enc"
SHA_FILE="${SESSION_DIR}/db.sqlite.enc.sha256"

if [[ ! -f "$DB_FILE" ]]; then
  echo "ERROR: no session sqlite found: $DB_FILE" >&2
  exit 1
fi

openssl enc -e -aes-256-cbc -pbkdf2 -iter 200000 \
  -in "$DB_FILE" -out "$ENC_FILE" -pass "pass:${SQLITE_KEY}"

sha256sum "$ENC_FILE" > "$SHA_FILE"

remote_base="${NC_WEBDAV_BASE%/}"
remote_rel="$(printf '%s' "$SQLITE_REMOTE" | sed 's#^/*##')"
remote_url="${remote_base}/${remote_rel}"
remote_sha_url="${remote_url}.sha256"

curl -fsS -u "${NC_USER}:${NC_PASS}" -T "$ENC_FILE" "$remote_url"
curl -fsS -u "${NC_USER}:${NC_PASS}" -T "$SHA_FILE" "$remote_sha_url"

if [[ "${KEEP_LOCAL:-0}" != "1" ]]; then
  rm -f "$DB_FILE" "$ENC_FILE" "$SHA_FILE"
  rmdir "$SESSION_DIR" 2>/dev/null || true
fi

echo "Uploaded: $remote_url"
