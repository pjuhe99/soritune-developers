#!/bin/bash
set -euo pipefail
SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec php "$SITE_ROOT/scripts/run_project_init.php" "$1"
