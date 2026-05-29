#!/bin/bash
# Run unit + integration (phpunit) then smoke tests.
# Smoke tests that need an admin session are skipped unless SDDEVSESSID is set.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== phpunit (unit + integration) ==="
vendor/bin/phpunit

echo "=== smoke tests ==="
for s in tests/smoke/*.sh; do
  base=$(basename "$s")
  # user_crud / project_register need an admin session cookie; skip if absent.
  if [[ "$base" == "user_crud.sh" || "$base" == "project_register.sh" ]] && [ -z "${SDDEVSESSID:-}" ]; then
    echo "--- $s (SKIP: set SDDEVSESSID to run) ---"
    continue
  fi
  echo "--- $s ---"
  bash "$s"
done
echo "All tests passed."
