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
#   SESSION_ROOT       default /tmp/budgetlove-sessions
#   ALLOW_INIT_EMPTY   1 allows creating an empty sqlite for first-time bootstrap

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

init_sqlite_file() {
  if command -v sqlite3 >/dev/null 2>&1; then
    sqlite3 "$DB_FILE" 'pragma journal_mode=wal;' >/dev/null 2>&1 || true
    return 0
  fi
  if command -v php >/dev/null 2>&1; then
    php -r '$f=$argv[1]; $pdo=new PDO("sqlite:$f"); $pdo->exec("pragma journal_mode=wal;");' "$DB_FILE" >/dev/null 2>&1 || true
  fi
}

mkdir -p "$SESSION_ROOT"
chmod 700 "$SESSION_ROOT" 2>/dev/null || true

if [ -e "$SESSION_DIR" ] && [ ! -w "$SESSION_DIR" ]; then
  rm -rf "$SESSION_DIR" 2>/dev/null || {
    echo "ERROR: runtime directory is not writable and cannot be replaced: $SESSION_DIR" >&2
    exit 6
  }
fi

mkdir -p "$SESSION_DIR"
chmod 700 "$SESSION_DIR"
LOCK_FILE="${SESSION_DIR}/.runtime.lock"
touch "$LOCK_FILE"

if command -v flock >/dev/null 2>&1; then
  exec 9>"$LOCK_FILE"
  flock 9
fi

remote_url="${NC_WEBDAV_BASE%/}/$(printf '%s' "$SQLITE_REMOTE" | sed 's#^/*##')"

if [ -s "$DB_FILE" ] && head -c 16 "$DB_FILE" | grep -q '^SQLite format 3'; then
  chmod 600 "$DB_FILE"
elif curl -fsS -u "${NC_USER}:${NC_PASS}" -o "$ENC_FILE" "$remote_url"; then
  if openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 \
    -in "$ENC_FILE" -out "$DB_FILE" -pass "pass:${SQLITE_KEY}"; then
    rm -f "$ENC_FILE"
    chmod 600 "$DB_FILE"
    if ! head -c 16 "$DB_FILE" | grep -q '^SQLite format 3'; then
      if [ "${ALLOW_INIT_EMPTY:-0}" = "1" ] && [ ! -s "$DB_FILE" ]; then
        rm -f "$DB_FILE"
        init_sqlite_file
      fi
      if ! head -c 16 "$DB_FILE" | grep -q '^SQLite format 3'; then
        rm -f "$DB_FILE"
        echo "ERROR: decrypted file is not a valid sqlite database" >&2
        exit 5
      fi
    fi
  else
    rm -f "$ENC_FILE" "$DB_FILE"
    echo "ERROR: cannot decrypt remote sqlite snapshot" >&2
    exit 2
  fi
else
  rm -f "$ENC_FILE" "$DB_FILE"
  if [ "${ALLOW_INIT_EMPTY:-0}" = "1" ]; then
    init_sqlite_file
    chmod 600 "$DB_FILE"
  else
    echo "ERROR: remote sqlite snapshot not found or not readable: $remote_url" >&2
    exit 3
  fi
fi

if ! head -c 16 "$DB_FILE" | grep -q '^SQLite format 3'; then
  rm -f "$DB_FILE"
  echo "ERROR: sqlite session file is invalid or empty" >&2
  exit 4
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
