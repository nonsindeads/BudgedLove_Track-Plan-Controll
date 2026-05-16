#!/bin/sh
set -eu

# Garbage-collect stale session sqlite files from /tmp.
#
# Usage:
#   sqlite-session-gc.sh [--root /tmp/budgetlove-sessions] [--ttl-minutes 30]

ROOT="/tmp/budgetlove-sessions"
TTL_MINUTES=30

while [ "$#" -gt 0 ]; do
  case "$1" in
    --root)
      ROOT="${2:-$ROOT}"
      shift 2
      ;;
    --ttl-minutes)
      TTL_MINUTES="${2:-$TTL_MINUTES}"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

[ -d "$ROOT" ] || exit 0

now="$(date +%s)"
ttl_sec=$((TTL_MINUTES * 60))

find "$ROOT" -mindepth 1 -maxdepth 1 -type d | while read -r d; do
  meta="$d/meta.env"
  last_touch=0
  if [ -f "$meta" ]; then
    # shellcheck disable=SC1090
    . "$meta" || true
    last_touch="${LAST_TOUCH:-0}"
  fi
  if [ "$last_touch" -eq 0 ]; then
    last_touch="$(stat -c '%Y' "$d" 2>/dev/null || echo 0)"
  fi
  age=$((now - last_touch))
  if [ "$age" -ge "$ttl_sec" ]; then
    find "$d" -type f -exec shred -u {} \; 2>/dev/null || true
    rm -rf "$d"
    echo "GC removed stale session dir: $d"
  fi
done
