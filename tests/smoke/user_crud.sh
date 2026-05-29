#!/bin/bash
# Smoke: authenticated admin can list users via API.
# Requires: SDDEVSESSID env var (copy from browser devtools after login).
set -euo pipefail
BASE="${BASE:-https://developers.soritune.com}"
: "${SDDEVSESSID:?Set SDDEVSESSID to an admin session cookie}"

resp=$(curl -s -H "Cookie: SDDEVSESSID=$SDDEVSESSID" "$BASE/api/system.php?action=users&op=list")
echo "$resp" | grep -q '"ok":true' || { echo "FAIL: user list: $resp"; exit 1; }
echo "PASS: user_crud smoke"
