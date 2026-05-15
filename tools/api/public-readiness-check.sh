#!/usr/bin/env bash
set -euo pipefail

# BudgetLove Public API Readiness Check (non-destructive where possible)
#
# Required env:
#   BASE_URL   e.g. https://app.budgetlove.de
#   TOKEN      Bearer token with broad scopes (legacy * token or oauth test token)
#
# Optional env for OAuth checks:
#   OAUTH_CLIENT_ID
#   OAUTH_CLIENT_SECRET
#   OAUTH_REDIRECT_URI
#   OAUTH_SCOPE
#
# Notes:
# - Script performs a few write checks (planned_payments/open_cases) and cleans up.
# - It verifies core public-readiness behavior: JSON errors, request_id, idempotency, scope/auth signals.

BASE_URL="${BASE_URL:-https://app.budgetlove.de}"
TOKEN="${TOKEN:-}"
TEST_ACCOUNT_ID="${TEST_ACCOUNT_ID:-8}"
TEST_CATEGORY_ID="${TEST_CATEGORY_ID:-66}"

if [[ -z "$TOKEN" ]]; then
  echo "ERROR: TOKEN env var is required"
  exit 1
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

pass() { echo "[PASS] $*"; }
fail() { echo "[FAIL] $*" >&2; exit 1; }

request() {
  local name="$1"
  shift
  local hdr="$TMP_DIR/${name}.headers"
  local body="$TMP_DIR/${name}.body"
  local code
  code="$(curl -sS -D "$hdr" -o "$body" "$@" -w "%{http_code}")"
  printf '%s' "$code"
}

json_has_request_id() {
  local file="$1"
  grep -q '"request_id"' "$file"
}

json_has_error_object() {
  local file="$1"
  grep -q '"error":{"code"' "$file"
}

echo "== BudgetLove Public API Readiness =="
echo "BASE_URL=$BASE_URL"

# 1) Unauthorized format + request_id
code="$(request unauth_meta "$BASE_URL/api/meta.php")"
[[ "$code" == "401" ]] || fail "unauth /api/meta.php expected 401 got $code"
json_has_error_object "$TMP_DIR/unauth_meta.body" || fail "unauth error format is not structured"
json_has_request_id "$TMP_DIR/unauth_meta.body" || fail "unauth response missing request_id"
pass "Unauthorized error format + request_id"

# 2) Auth metadata works
code="$(request auth_meta -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/meta.php")"
[[ "$code" == "200" ]] || fail "auth /api/meta.php expected 200 got $code"
json_has_request_id "$TMP_DIR/auth_meta.body" || fail "auth meta missing request_id"
pass "Authenticated metadata response"

# 3) Planned payment create + idempotent retry + conflict
IDEMP_KEY="readiness-planned-$(date +%s)"
CREATE_A='{"name":"Readiness Planned A","direction":"expense","amount":1.11,"planned_date":"2026-12-01","account_id":8,"category_id":66}'
CREATE_B='{"name":"Readiness Planned B","direction":"expense","amount":9.99,"planned_date":"2026-12-01","account_id":8,"category_id":66}'
CREATE_A="${CREATE_A/\"account_id\":8/\"account_id\":${TEST_ACCOUNT_ID}}"
CREATE_A="${CREATE_A/\"category_id\":66/\"category_id\":${TEST_CATEGORY_ID}}"
CREATE_B="${CREATE_B/\"account_id\":8/\"account_id\":${TEST_ACCOUNT_ID}}"
CREATE_B="${CREATE_B/\"category_id\":66/\"category_id\":${TEST_CATEGORY_ID}}"

code="$(request planned_create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMP_KEY" \
  -X POST "$BASE_URL/api/planned_payments.php" \
  --data "$CREATE_A")"
[[ "$code" == "201" ]] || fail "planned create expected 201 got $code"
PLANNED_ID="$(grep -o '"id":[0-9]\+' "$TMP_DIR/planned_create.body" | head -n1 | cut -d: -f2)"
[[ -n "$PLANNED_ID" ]] || fail "planned create did not return id"

code="$(request planned_retry \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMP_KEY" \
  -X POST "$BASE_URL/api/planned_payments.php" \
  --data "$CREATE_A")"
[[ "$code" == "201" ]] || fail "planned retry expected 201 got $code"
json_has_request_id "$TMP_DIR/planned_retry.body" || fail "planned idempotent retry missing request_id"

code="$(request planned_conflict \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $IDEMP_KEY" \
  -X POST "$BASE_URL/api/planned_payments.php" \
  --data "$CREATE_B")"
[[ "$code" == "409" ]] || fail "planned idempotency conflict expected 409 got $code"
json_has_error_object "$TMP_DIR/planned_conflict.body" || fail "planned conflict response not structured"
pass "Planned payment idempotency + conflict handling"

# 4) Backward compatibility: status=resolved filter
code="$(request planned_list_resolved \
  -H "Authorization: Bearer $TOKEN" \
  "$BASE_URL/api/planned_payments.php?status=resolved&limit=3")"
[[ "$code" == "200" ]] || fail "planned list resolved expected 200 got $code"
pass "Planned payments legacy status filter"

# 5) Open cases write path basic check
CASE_KEY="readiness-case-$(date +%s)"
CASE_CREATE='{"title":"Readiness Case","status":"open","notes":"temp"}'
code="$(request case_create \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: $CASE_KEY" \
  -X POST "$BASE_URL/api/open_cases.php" \
  --data "$CASE_CREATE")"
[[ "$code" == "201" ]] || fail "open case create expected 201 got $code"
CASE_ID="$(grep -o '"id":[0-9]\+' "$TMP_DIR/case_create.body" | head -n1 | cut -d: -f2)"
[[ -n "$CASE_ID" ]] || fail "case create did not return id"
pass "Open cases write path"

# 6) DELETE not found semantics (planned)
code="$(request planned_delete_404 \
  -H "Authorization: Bearer $TOKEN" \
  -X DELETE "$BASE_URL/api/planned_payments.php?id=99999999")"
[[ "$code" == "404" ]] || fail "planned delete missing-id expected 404 got $code"
json_has_error_object "$TMP_DIR/planned_delete_404.body" || fail "planned delete 404 response not structured"
pass "DELETE 404 semantics"

# cleanup writes
request cleanup_planned \
  -H "Authorization: Bearer $TOKEN" \
  -X DELETE "$BASE_URL/api/planned_payments.php?id=$PLANNED_ID" >/dev/null || true
request cleanup_case \
  -H "Authorization: Bearer $TOKEN" \
  -X DELETE "$BASE_URL/api/open_cases.php?id=$CASE_ID" >/dev/null || true

# 7) Optional OAuth token endpoint basic check (if env is present)
if [[ -n "${OAUTH_CLIENT_ID:-}" && -n "${OAUTH_CLIENT_SECRET:-}" ]]; then
  code="$(request oauth_bad_grant \
    -H "Content-Type: application/x-www-form-urlencoded" \
    -X POST "$BASE_URL/api/oauth/token.php" \
    --data "grant_type=authorization_code&client_id=${OAUTH_CLIENT_ID}&client_secret=${OAUTH_CLIENT_SECRET}&code=invalid&code_verifier=invalid&redirect_uri=${OAUTH_REDIRECT_URI:-}")"
  [[ "$code" == "400" || "$code" == "401" ]] || fail "oauth bad grant expected 400/401 got $code"
  grep -qi '^cache-control: no-store' "$TMP_DIR/oauth_bad_grant.headers" || fail "oauth token response missing Cache-Control: no-store"
  pass "OAuth token endpoint baseline headers"
fi

echo "== Readiness check completed successfully =="
