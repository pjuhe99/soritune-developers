#!/bin/bash
# Smoke: authenticated admin can list projects via API.
set -euo pipefail
BASE="${BASE:-https://developers.soritune.com}"
: "${SDDEVSESSID:?Set SDDEVSESSID to an admin session cookie}"

resp=$(curl -s -H "Cookie: SDDEVSESSID=$SDDEVSESSID" "$BASE/api/system.php?action=projects&op=list")
echo "$resp" | grep -q '"ok":true' || { echo "FAIL: project list: $resp"; exit 1; }
echo "PASS: project_register smoke"
