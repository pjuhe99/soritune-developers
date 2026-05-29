#!/bin/bash
# Plan A: skeleton worker. Drains pending jobs, marks each success immediately
# (no real job handlers yet — Plan B/C). flock prevents overlapping runs.
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE_ROOT"

LOCK="/tmp/developers_worker.lock"
exec 9>"$LOCK"
flock -n 9 || exit 0   # another worker running

php -r '
require "vendor/autoload.php";
require "public_html/config.php";
use Soritune\Developers\JobQueue;
$n = 0;
while (($job = JobQueue::claimNext()) !== null) {
    // Per-job try/catch: one failing job must not abort the drain (set -e) nor leave
    // a claimed job stuck in "running" forever — mark it failed instead.
    try {
        JobQueue::markDone((int)$job["id"], true, null, ["note" => "Plan A stub - no handler"]);
    } catch (\Throwable $e) {
        JobQueue::markDone((int)$job["id"], false, $e->getMessage());
    }
    $n++;
    if ($n >= 50) break; // safety cap per run
}
// Log only when work happened, so worker.log does not grow every idle minute.
if ($n > 0) echo "processed $n job(s)\n";
'
