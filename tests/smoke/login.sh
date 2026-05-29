#!/bin/bash
# Smoke: login form renders with CSRF token + wrong password is rejected.
set -euo pipefail
BASE="${BASE:-https://developers.soritune.com}"
COOKIE=$(mktemp)
trap 'rm -f "$COOKIE"' EXIT

html=$(curl -s -c "$COOKIE" -b "$COOKIE" "$BASE/login.php")
echo "$html" | grep -q '_csrf' || { echo "FAIL: no csrf token in login form"; exit 1; }
csrf=$(echo "$html" | grep -oP '(?<=name="_csrf" value=")[^"]+')

code=$(curl -s -o /dev/null -w '%{http_code}' -c "$COOKIE" -b "$COOKIE" \
  -X POST "$BASE/login.php" -d "_csrf=$csrf&username=admin&password=wrongpass")
# Wrong password re-renders the form (200) with error, does NOT redirect (302)
[ "$code" = "200" ] || { echo "FAIL: wrong password gave HTTP $code (expected 200)"; exit 1; }
echo "PASS: login smoke"
