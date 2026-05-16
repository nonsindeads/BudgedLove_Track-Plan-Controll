#!/bin/sh
set -eu

# Downloads an encrypted SQLite snapshot from Nextcloud WebDAV,
# decrypts it into /tmp for the current session and prints export lines.
#
# Required env:
#   NC_WEBDAV_BASE   e.g. https://cloud.example.com/remote.php/dav/files/USER
#   NC_USER
#   NC_PASS          app password
#   SQLITE_REMOTE    e.g. BudgetLove/household-4/budgetlove.sqlite.enc
#   SQLITE_KEY       passphrase for openssl
#   SESSION_ID       unique session token (or php session id)
# Optional:
#   SESSION_ROOT     default /tmp/budgetlove-sessions

for v in NC_WEBDAV_BASE NC_USER NC_PASS SQLITE_REMOTE SQLITE_KEY SESSION_ID; do
  eval "val=\${$v-}"
  if [ -z "${val}" ]; then
    echo "ERROR: missing env $v" >&2
    exit 1
  fi
done

SESSION_ROOT="${SESSION_ROOT:-/tmp/budgetlove-sessions}"
SESSION_DIR="${SESSION_ROOT%/}/${SESSION_ID}"
ENC_FILE="${SESSION_DIR}/db.sqlite.enc"
DB_FILE="${SESSION_DIR}/db.sqlite"
META_FILE="${SESSION_DIR}/meta.env"

mkdir -p "$SESSION_DIR"
chmod 700 "$SESSION_DIR"

remote_url="${NC_WEBDAV_BASE%/}/$(printf '%s' "$SQLITE_REMOTE" | sed 's#^/*##')"

if curl -fsS -u "${NC_USER}:${NC_PASS}" -o "$ENC_FILE" "$remote_url"; then
  if openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
    -in "$ENC_FILE" -out "$DB_FILE" -pass "pass:${SQLITE_KEY}"; then
    rm -f "$ENC_FILE"
    chmod 600 "$DB_FILE"
  else
    rm -f "$ENC_FILE"
    : > "$DB_FILE"
    chmod 600 "$DB_FILE"
  fi
else
  : > "$DB_FILE"
  chmod 600 "$DB_FILE"
fi

cat > "$META_FILE" <<EOF
SESSION_ID=${SESSION_ID}
SESSION_DIR=${SESSION_DIR}
SQLITE_REMOTE=${SQLITE_REMOTE}
STARTED_AT=$(date +%s)
LAST_TOUCH=$(date +%s)
EOF
chmod 600 "$META_FILE"

echo "HB_SQLITE_PATH=${DB_FILE}"
echo "HB_SQLITE_SESSION_DIR=${SESSION_DIR}"
