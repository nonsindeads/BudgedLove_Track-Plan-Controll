#!/usr/bin/env bash
set -euo pipefail

# Build an iCloud/Cloud-drive friendly export bundle:
# - payload.tar.gz (from SOURCE_DIR)
# - manifest.json (file list + hashes + metadata)
# - payload.tar.gz.sha256
#
# Usage:
#   build-cloud-bundle.sh --source-dir /path/to/export --out-dir /path/to/out [--bundle-name budgetlove-export]
#
# Notes:
# - This is provider-agnostic and can be synced by iCloud Drive, Nextcloud, etc.
# - Intended for backup/export artifacts (and later SQLite snapshots), not live DB files.

SOURCE_DIR=""
OUT_DIR=""
BUNDLE_NAME="budgetlove-export"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --source-dir)
      SOURCE_DIR="${2:-}"
      shift 2
      ;;
    --out-dir)
      OUT_DIR="${2:-}"
      shift 2
      ;;
    --bundle-name)
      BUNDLE_NAME="${2:-}"
      shift 2
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 1
      ;;
  esac
done

if [[ -z "$SOURCE_DIR" || -z "$OUT_DIR" ]]; then
  echo "Usage: $0 --source-dir <dir> --out-dir <dir> [--bundle-name <name>]" >&2
  exit 1
fi

if [[ ! -d "$SOURCE_DIR" ]]; then
  echo "ERROR: source dir not found: $SOURCE_DIR" >&2
  exit 1
fi

mkdir -p "$OUT_DIR"

ts="$(date -u +%Y%m%dT%H%M%SZ)"
bundle_dir="${OUT_DIR%/}/${BUNDLE_NAME}-${ts}"
mkdir -p "$bundle_dir"

payload_file="${bundle_dir}/payload.tar.gz"
manifest_file="${bundle_dir}/manifest.json"
checksum_file="${bundle_dir}/payload.tar.gz.sha256"
files_tmp="$(mktemp)"
trap 'rm -f "$files_tmp"' EXIT

# Normalize archive root to "payload/"
tar -C "$SOURCE_DIR" -czf "$payload_file" .

# Build manifest entries from source tree
find "$SOURCE_DIR" -type f | sort > "$files_tmp"

{
  echo "{"
  echo "  \"bundle_name\": \"${BUNDLE_NAME}\","
  echo "  \"created_at_utc\": \"$(date -u +%Y-%m-%dT%H:%M:%SZ)\","
  echo "  \"source_dir\": \"${SOURCE_DIR}\","
  echo "  \"payload_file\": \"$(basename "$payload_file")\","
  echo "  \"payload_sha256\": \"$(sha256sum "$payload_file" | awk '{print $1}')\","
  echo "  \"payload_size_bytes\": $(stat -c '%s' "$payload_file"),"
  echo "  \"files\": ["

  first=1
  while IFS= read -r file; do
    rel="${file#"$SOURCE_DIR"/}"
    hash="$(sha256sum "$file" | awk '{print $1}')"
    size="$(stat -c '%s' "$file")"
    mtime="$(date -u -d "@$(stat -c '%Y' "$file")" +%Y-%m-%dT%H:%M:%SZ)"
    if [[ $first -eq 0 ]]; then
      echo ","
    fi
    first=0
    printf '    {"path":"%s","size_bytes":%s,"mtime_utc":"%s","sha256":"%s"}' \
      "$rel" "$size" "$mtime" "$hash"
  done < "$files_tmp"
  echo
  echo "  ]"
  echo "}"
} > "$manifest_file"

sha256sum "$payload_file" > "$checksum_file"

echo "Bundle created:"
echo "  ${bundle_dir}"
echo "Files:"
echo "  - $(basename "$payload_file")"
echo "  - $(basename "$manifest_file")"
echo "  - $(basename "$checksum_file")"
