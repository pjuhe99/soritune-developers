#!/bin/bash
# Idempotent migration runner. Tracks applied files in `schema_migrations` table.
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE_ROOT"

# shellcheck disable=SC1091
set -a
. ./.db_credentials
set +a

mysql_cmd() {
  mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@"
}

mysql_cmd <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL

applied=$(mysql_cmd -N -B -e "SELECT filename FROM schema_migrations")

for f in migrations/*.sql; do
  base=$(basename "$f")
  case "$base" in *.template) continue ;; esac
  if grep -qFx "$base" <<<"$applied"; then
    echo "SKIP  $base"
    continue
  fi
  echo "APPLY $base"
  mysql_cmd < "$f"
  mysql_cmd -e "INSERT INTO schema_migrations (filename) VALUES ('$base')"
done

echo "Done."
