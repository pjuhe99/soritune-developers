<?php
declare(strict_types=1);
// requireAdmin() already called by router.

use Soritune\Developers\JobQueue;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

// Each case ends with return; (web mode jsonResponse exits anyway — belt & suspenders).
switch ($op) {
    case 'list': {
        $rows = getDB()->query(
            "SELECT j.*, u.username AS actor FROM jobs j LEFT JOIN users u ON u.id = j.user_id ORDER BY j.enqueued_at DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
        jsonSuccess(['jobs' => $rows]);
        return;
    }

    case 'enqueue_test': {
        // Plan A: lets an admin exercise the worker pipeline end-to-end (no real
        // handler yet — the worker just marks it success). Proves cron+queue work.
        $id = JobQueue::enqueue('dev_deploy', ['plan_a_test' => true], (int)currentUser()['id']);
        jsonSuccess(['job_id' => $id], 'enqueued');
        return;
    }

    default:
        jsonError("unknown op: $op", 404);
        return;
}
