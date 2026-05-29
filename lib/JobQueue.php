<?php
declare(strict_types=1);
namespace Soritune\Developers;

use PDO;

final class JobQueue
{
    /** Valid job types (match jobs.type ENUM). */
    public const TYPES = ['project_init','site_create','dev_deploy','prod_deploy','user_repo_grant'];

    public static function enqueue(string $type, array $payload, int $userId, ?int $projectId = null, ?int $taskId = null): int
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("invalid job type: $type");
        }
        $db = getDB();
        $st = $db->prepare(
            "INSERT INTO jobs (type, status, project_id, task_id, user_id, payload)
             VALUES (?, 'pending', ?, ?, ?, ?)"
        );
        $st->execute([$type, $projectId, $taskId, $userId, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        return (int)$db->lastInsertId();
    }

    /** Atomically claim the oldest pending job.
     *  Uses UPDATE+SELECT for MariaDB 10.5 compatibility (no SKIP LOCKED needed).
     *  Composable: reuses an existing transaction if one is already active. */
    public static function claimNext(): ?array
    {
        $db = getDB();
        $ownTx = !$db->inTransaction();
        if ($ownTx) $db->beginTransaction();
        try {
            // Find the oldest pending job id while holding a row lock via FOR UPDATE.
            // MariaDB 10.5 supports FOR UPDATE but not SKIP LOCKED — single-worker
            // skeleton (Plan A) so no concurrency concern; row lock is sufficient.
            $sub = $db->query(
                "SELECT id FROM jobs WHERE status = 'pending' ORDER BY enqueued_at ASC LIMIT 1 FOR UPDATE"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$sub) {
                if ($ownTx) $db->commit();
                return null;
            }
            $id = (int)$sub['id'];
            $db->prepare("UPDATE jobs SET status = 'running', started_at = NOW(), attempts = attempts + 1 WHERE id = ?")
               ->execute([$id]);
            $sel = $db->prepare("SELECT j.*, u.username AS actor FROM jobs j LEFT JOIN users u ON u.id = j.user_id WHERE j.id = ?");
            $sel->execute([$id]);
            $row = $sel->fetch(PDO::FETCH_ASSOC);
            if ($ownTx) $db->commit();
            $row['status'] = 'running';
            return $row;
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function markDone(int $jobId, bool $success, ?string $error = null, ?array $result = null): void
    {
        $db = getDB();
        $status = $success ? 'success' : 'failed';
        $db->prepare("UPDATE jobs SET status = ?, finished_at = NOW(), error_message = ?, result = ? WHERE id = ?")
           ->execute([$status, $error, $result !== null ? json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null, $jobId]);
    }
}
