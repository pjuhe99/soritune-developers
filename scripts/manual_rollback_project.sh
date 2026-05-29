#!/bin/bash
# manual_rollback_project.sh <slug>
#
# Best-effort cleanup of a project whose project_init partially failed.
# Each step is independent: a missing piece is skipped, not fatal. Destructive
# DB/dir removal is delegated to site_manager's backup_and_clean (it backs up
# first). Route53 record deletion is printed as an instruction rather than done
# automatically, to avoid deleting the wrong record.
#
# Run as a user that can read .env and write the site_manager queue (e.g. the
# deploy operator). Reads GITHUB_ACCOUNT / GITHUB_TOKEN from the portal .env.
#
# Usage: scripts/manual_rollback_project.sh <slug>
set -uo pipefail

SLUG="${1:-}"
if [ -z "$SLUG" ]; then
    echo "Usage: $0 <slug>"
    exit 1
fi
# slug sanity (same shape the portal enforces)
if ! [[ "$SLUG" =~ ^[a-z][a-z0-9-]{0,37}[a-z0-9]$ ]]; then
    echo "Refusing: '$SLUG' is not a valid slug."
    exit 1
fi

ROOT="/var/www/html/_______site_SORITUNECOM_DEVELOPERS"
SM_PENDING="/var/www/html/_______site_SORITUNECOM_APP/jobs/pending"

# Load portal .env (GITHUB_ACCOUNT, GITHUB_TOKEN) if readable
if [ -r "$ROOT/.env" ]; then
    set -a; . "$ROOT/.env"; set +a
fi
ACCT="${GITHUB_ACCOUNT:-}"

echo "=== Rollback project '$SLUG' (github account='$ACCT') ==="
echo "This deletes the GitHub repo and queues site teardown. Ctrl-C within 5s to abort."
sleep 5

# 1. GitHub repo (deleting the repo also removes its rulesets)
if [ -n "$ACCT" ] && [ -n "${GITHUB_TOKEN:-}" ]; then
    if GH_TOKEN="$GITHUB_TOKEN" gh repo view "$ACCT/$SLUG" >/dev/null 2>&1; then
        GH_TOKEN="$GITHUB_TOKEN" gh repo delete "$ACCT/$SLUG" --yes \
            && echo "  [ok] deleted GitHub repo $ACCT/$SLUG" \
            || echo "  [warn] gh repo delete failed (check token's delete_repo scope)"
    else
        echo "  [skip] GitHub repo $ACCT/$SLUG not found"
    fi
else
    echo "  [skip] GITHUB_ACCOUNT/GITHUB_TOKEN not set — cannot touch GitHub"
fi

# 2. Route53 A records — printed instruction (manual, to avoid wrong-record deletion)
echo "  [manual] If Route53 A records were created, delete them in the console:"
echo "           dev-$SLUG.soritune.com   and   $SLUG.soritune.com"

# 3. site_manager backup_and_clean for dev + prod (queues to the existing root cron worker)
if [ -d "$SM_PENDING" ]; then
    for SUB in "dev-$SLUG" "$SLUG"; do
        ID="rb_$(date +%s)_${RANDOM}"
        printf '{"action":"backup_and_clean","subdomain":"%s"}\n' "$SUB" > "$SM_PENDING/$ID.json" \
            && echo "  [queued] site_manager backup_and_clean $SUB ($ID.json)" \
            || echo "  [warn] could not write site_manager job for $SUB"
    done
    echo "  (site_manager backs up, then removes vhost/cert/DB/dir. Watch its done/ dir.)"
else
    echo "  [skip] site_manager queue not found at $SM_PENDING"
fi

# 4. Portal DB: mark the project archived (no row deletion — audit-preserving)
echo "  [manual] In the portal DB, archive the project row:"
echo "           UPDATE projects SET status='archived' WHERE slug='$SLUG';"
echo "           (run via: mysql with $ROOT/.db_credentials)"

echo "=== Rollback steps queued/printed. Verify site_manager done/ + Route53 console. ==="
