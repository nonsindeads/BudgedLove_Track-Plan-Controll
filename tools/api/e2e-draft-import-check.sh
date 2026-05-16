#!/usr/bin/env bash
set -euo pipefail

# BudgetLove E2E helper:
# 1) create: create receipt draft via API + generate CAMT XML fixture
# 2) verify: verify matched draft and no duplicate open booking
#
# Required env:
#   BASE_URL
#   TOKEN
#
# Required for create:
#   ACCOUNT_ID
# Optional for create:
#   CATEGORY_ID
#   AMOUNT (default 12.34)
#   DATE (default today YYYY-MM-DD)
#
# verify needs:
#   --state /path/to/state.json

BASE_URL="${BASE_URL:-https://app.budgetlove.de}"
TOKEN="${TOKEN:-}"

usage() {
  cat <<'EOF'
Usage:
  e2e-draft-import-check.sh create
  e2e-draft-import-check.sh verify --state /tmp/budgetlove-e2e-*.json

Env:
  BASE_URL=https://app.budgetlove.de
  TOKEN=...
  ACCOUNT_ID=...
  CATEGORY_ID=...   (optional)
  AMOUNT=12.34      (optional, create)
  DATE=YYYY-MM-DD   (optional, create)
EOF
}

if [[ $# -lt 1 ]]; then
  usage
  exit 1
fi

if [[ -z "$TOKEN" ]]; then
  echo "ERROR: TOKEN env var is required" >&2
  exit 1
fi

command="$1"
shift || true

tmp_dir="$(mktemp -d)"
trap 'rm -rf "$tmp_dir"' EXIT

http_json() {
  local method="$1"
  local url="$2"
  local body="${3:-}"
  local headers="$tmp_dir/headers"
  local out="$tmp_dir/body"
  local code
  if [[ -n "$body" ]]; then
    code="$(curl -sS -D "$headers" -o "$out" -w "%{http_code}" \
      -H "Authorization: Bearer $TOKEN" \
      -H "Content-Type: application/json" \
      -X "$method" "$url" --data "$body")"
  else
    code="$(curl -sS -D "$headers" -o "$out" -w "%{http_code}" \
      -H "Authorization: Bearer $TOKEN" \
      -X "$method" "$url")"
  fi
  printf '%s' "$code"
}

json_read() {
  local expr="$1"
  php -r '
    $j = json_decode(stream_get_contents(STDIN), true);
    if (!is_array($j)) { exit(2); }
    $expr = $argv[1];
    $parts = explode(".", $expr);
    $cur = $j;
    foreach ($parts as $p) {
      if ($p === "") continue;
      if (preg_match("/^\[(\d+)\]$/", $p, $m)) {
        $idx = (int)$m[1];
        if (!is_array($cur) || !array_key_exists($idx, $cur)) { exit(3); }
        $cur = $cur[$idx];
        continue;
      }
      if (!is_array($cur) || !array_key_exists($p, $cur)) { exit(4); }
      $cur = $cur[$p];
    }
    if (is_bool($cur)) { echo $cur ? "true" : "false"; }
    else if ($cur === null) { echo "null"; }
    else if (is_scalar($cur)) { echo (string)$cur; }
    else { echo json_encode($cur); }
  ' "$expr"
}

make_camt_fixture() {
  local file="$1"
  local date="$2"
  local amount="$3"
  local payee="$4"
  local e2eid="$5"

  cat > "$file" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.08">
  <BkToCstmrAcctRpt>
    <Rpt>
      <Ntry>
        <Amt Ccy="EUR">${amount}</Amt>
        <CdtDbtInd>DBIT</CdtDbtInd>
        <Sts><Cd>BOOK</Cd></Sts>
        <BookgDt><Dt>${date}</Dt></BookgDt>
        <ValDt><Dt>${date}</Dt></ValDt>
        <AcctSvcrRef>E2E-${e2eid}</AcctSvcrRef>
        <NtryDtls>
          <TxDtls>
            <Refs>
              <EndToEndId>E2E-${e2eid}</EndToEndId>
            </Refs>
            <Amt Ccy="EUR">${amount}</Amt>
            <RltdPties>
              <Cdtr>
                <Pty>
                  <Nm>${payee}</Nm>
                </Pty>
              </Cdtr>
            </RltdPties>
            <RmtInf>
              <Ustrd>BudgetLove E2E fixture</Ustrd>
            </RmtInf>
          </TxDtls>
        </NtryDtls>
      </Ntry>
    </Rpt>
  </BkToCstmrAcctRpt>
</Document>
EOF
}

if [[ "$command" == "create" ]]; then
  if [[ -z "${ACCOUNT_ID:-}" ]]; then
    echo "ERROR: ACCOUNT_ID env var is required for create" >&2
    exit 1
  fi
  date_value="${DATE:-$(date +%F)}"
  amount_value="${AMOUNT:-12.34}"
  run_id="$(date +%s)"
  payee_value="E2E Draft ${run_id}"
  note_value="e2e-draft-import-${run_id}"

  if [[ -n "${CATEGORY_ID:-}" ]]; then
    payload="{\"type\":\"expense\",\"amount\":${amount_value},\"date\":\"${date_value}\",\"account_id\":${ACCOUNT_ID},\"category_id\":${CATEGORY_ID},\"payee\":\"${payee_value}\",\"notes\":\"${note_value}\"}"
  else
    payload="{\"type\":\"expense\",\"amount\":${amount_value},\"date\":\"${date_value}\",\"account_id\":${ACCOUNT_ID},\"payee\":\"${payee_value}\",\"notes\":\"${note_value}\"}"
  fi

  code="$(http_json POST "$BASE_URL/api/transaction_drafts.php" "$payload")"
  [[ "$code" == "201" ]] || {
    echo "ERROR: draft create failed, HTTP $code"
    cat "$tmp_dir/body"
    exit 1
  }
  draft_id="$(json_read "id" < "$tmp_dir/body")"
  [[ -n "$draft_id" ]] || {
    echo "ERROR: no draft id returned"
    cat "$tmp_dir/body"
    exit 1
  }

  state_file="/tmp/budgetlove-e2e-draft-${run_id}.json"
  camt_file="/tmp/budgetlove-e2e-draft-${run_id}.xml"
  make_camt_fixture "$camt_file" "$date_value" "$amount_value" "$payee_value" "$run_id"

  cat > "$state_file" <<EOF
{
  "run_id": "${run_id}",
  "base_url": "${BASE_URL}",
  "draft_id": ${draft_id},
  "date": "${date_value}",
  "amount": "${amount_value}",
  "payee": "${payee_value}",
  "note_marker": "${note_value}",
  "account_id": ${ACCOUNT_ID},
  "camt_file": "${camt_file}"
}
EOF

  echo "Created draft id: ${draft_id}"
  echo "State file: ${state_file}"
  echo "CAMT fixture: ${camt_file}"
  echo ""
  echo "Next:"
  echo "1) Import ${camt_file} in /import.php on account ${ACCOUNT_ID}"
  echo "2) Run: $0 verify --state ${state_file}"
  exit 0
fi

if [[ "$command" == "verify" ]]; then
  state_file=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --state)
        state_file="${2:-}"
        shift 2
        ;;
      *)
        echo "Unknown arg: $1" >&2
        usage
        exit 1
        ;;
    esac
  done

  if [[ -z "$state_file" || ! -f "$state_file" ]]; then
    echo "ERROR: --state file is required" >&2
    exit 1
  fi

  draft_id="$(json_read "draft_id" < "$state_file")"
  date_value="$(json_read "date" < "$state_file")"
  payee_value="$(json_read "payee" < "$state_file")"
  amount_value="$(json_read "amount" < "$state_file")"

  code="$(http_json GET "$BASE_URL/api/transactions.php?id=${draft_id}")"
  [[ "$code" == "200" ]] || {
    echo "ERROR: cannot load draft transaction id ${draft_id}, HTTP $code"
    cat "$tmp_dir/body"
    exit 1
  }

  import_hash="$(json_read "transaction.import_hash" < "$tmp_dir/body")"
  is_reviewed="$(json_read "transaction.is_reviewed" < "$tmp_dir/body")"
  tx_amount_cents="$(json_read "transaction.amount_cents" < "$tmp_dir/body")"
  expected_cents="$(php -r 'echo (int)round(((float)$argv[1]) * 100);' "$amount_value")"

  [[ "$tx_amount_cents" == "$expected_cents" ]] || {
    echo "FAIL: amount mismatch on draft id ${draft_id} (got ${tx_amount_cents}, expected ${expected_cents})"
    exit 1
  }
  [[ "$is_reviewed" == "false" ]] || {
    echo "FAIL: draft should still be open (is_reviewed=false), got ${is_reviewed}"
    exit 1
  }
  [[ "$import_hash" != "null" && -n "$import_hash" ]] || {
    echo "FAIL: draft not matched yet (import_hash is empty)"
    exit 1
  }

  # Duplicate check over open transactions in date window
  date_from="$(php -r '$d=new DateTimeImmutable($argv[1]); echo $d->modify("-5 days")->format("Y-m-d");' "$date_value")"
  date_to="$(php -r '$d=new DateTimeImmutable($argv[1]); echo $d->modify("+5 days")->format("Y-m-d");' "$date_value")"
  code="$(http_json GET "$BASE_URL/api/transactions.php?reviewed=false&type=expense&date_from=${date_from}&date_to=${date_to}&q=$(php -r 'echo rawurlencode($argv[1]);' "$payee_value")&limit=100")"
  [[ "$code" == "200" ]] || {
    echo "ERROR: list query failed HTTP $code"
    cat "$tmp_dir/body"
    exit 1
  }

  dup_count="$(php -r '
    $j=json_decode(file_get_contents($argv[1]), true);
    $needle=(int)$argv[2];
    $n=0;
    foreach (($j["transactions"] ?? []) as $tx) {
      if ((int)($tx["amount_cents"] ?? 0) === $needle) $n++;
    }
    echo $n;
  ' "$tmp_dir/body" "$expected_cents")"
  [[ "$dup_count" -le 1 ]] || {
    echo "FAIL: possible duplicate open bookings detected (count=${dup_count})"
    exit 1
  }

  echo "PASS: draft matched and no duplicate open booking detected."
  echo "Matched draft id: ${draft_id}"
  echo "Import hash: ${import_hash}"
  exit 0
fi

usage
exit 1
