#!/bin/sh
set -eu

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
  eval "val=\${$v-}"
  if [ -z "${val}" ]; then
    echo "ERROR: missing env $v" >&2
    exit 1
  fi
done

SESSION_ROOT="${SESSION_ROOT:-/tmp/budgetlove-sessions}"
SESSION_DIR="${SESSION_ROOT%/}/${SESSION_ID}"
DB_FILE="${SESSION_DIR}/db.sqlite"
ENC_FILE="${SESSION_DIR}/db.sqlite.enc"
SHA_FILE="${SESSION_DIR}/db.sqlite.enc.sha256"
META_FILE="${SESSION_DIR}/meta.env"

if [ ! -f "$DB_FILE" ]; then
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

remote_dir="$(dirname "$remote_rel")"
if [ "$remote_dir" != "." ] && [ -n "$remote_dir" ]; then
  old_ifs="${IFS}"
  IFS='/'
  set -- $remote_dir
  IFS="${old_ifs}"
  curr="${remote_base}"
  for part in "$@"; do
    [ -n "$part" ] || continue
    curr="${curr}/${part}"
    code="$(curl -s -o /dev/null -w '%{http_code}' -u "${NC_USER}:${NC_PASS}" -X MKCOL "$curr" || true)"
    case "$code" in
      201|405) ;;
      *)
        echo "ERROR: cannot create remote directory ${curr} (HTTP ${code})" >&2
        exit 1
        ;;
    esac
  done
fi

curl -fsS -u "${NC_USER}:${NC_PASS}" -T "$ENC_FILE" "$remote_url"
curl -fsS -u "${NC_USER}:${NC_PASS}" -T "$SHA_FILE" "$remote_sha_url"

if [ "${KEEP_LOCAL:-0}" != "1" ]; then
  rm -f "$DB_FILE" "$ENC_FILE" "$SHA_FILE" "$META_FILE"
  rmdir "$SESSION_DIR" 2>/dev/null || true
fi

echo "Uploaded: $remote_url"
