#!/bin/bash
# Smoke: API rejects unauthenticated job list.
set -euo pipefail
BASE="${BASE:-https://developers.soritune.com}"
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/system.php?action=jobs&op=list")
[ "$code" = "401" ] || { echo "FAIL: unauth job list gave $code (expected 401)"; exit 1; }
echo "PASS: job_enqueue smoke (unauth rejected)"
