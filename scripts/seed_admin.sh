#!/bin/bash
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE_ROOT"

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ]; then
    echo "Usage: $0 <username> <display_name>"
    echo "(Will prompt for password)"
    exit 1
fi

username="$1"
display="$2"

read -srp "Password (>=12 chars): " pw; echo
if [ "${#pw}" -lt 12 ]; then
    echo "Password must be at least 12 characters."
    exit 1
fi

hash=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 10]);' "$pw")

set -a; . ./.db_credentials; set +a
sed -e "s|__USERNAME__|$username|g" \
    -e "s|__HASH__|$hash|g" \
    -e "s|__DISPLAY__|$display|g" \
    migrations/007_seed_admin.sql.template \
  | mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"

echo "Admin '$username' seeded (must change password on first login)."
