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

php -r '
require dirname(__DIR__) . "/public_html/config.php";
$pw = $argv[3];
$hash = password_hash($pw, PASSWORD_BCRYPT, ["cost" => 10]);
$db = getDB();
$st = $db->prepare("INSERT INTO users (username, password_hash, display_name, role, must_change_password)
    VALUES (?, ?, ?, \"admin\", 1)
    ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash), must_change_password=1");
$st->execute([$argv[1], $hash, $argv[2]]);
' "$username" "$display" "$pw"

echo "Admin '$username' seeded (must change password on first login)."
