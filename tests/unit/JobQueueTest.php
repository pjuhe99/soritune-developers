<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\JobQueue;
use PDO;

final class JobQueueTest extends TestCase
{
    public function testEnqueueCreatesJobRow(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO users (username, password_hash, display_name, role) VALUES ('jq_t', 'x', 'JQ', 'admin')");
            $uid = (int)$db->lastInsertId();

            $jobId = JobQueue::enqueue('dev_deploy', ['task_id' => 1], $uid, null, 1);
            $this->assertGreaterThan(0, $jobId);

            $row = $db->query("SELECT * FROM jobs WHERE id = $jobId")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('dev_deploy', $row['type']);
            $this->assertSame('pending', $row['status']);
            $payload = json_decode($row['payload'], true);
            $this->assertSame(1, $payload['task_id']);
        } finally {
            $db->rollBack();
        }
    }

    public function testClaimMarksRunning(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            $db->exec("INSERT INTO users (username, password_hash, display_name, role) VALUES ('jq_t2', 'x', 'JQ2', 'admin')");
            $uid = (int)$db->lastInsertId();
            $jobId = JobQueue::enqueue('site_create', ['slug' => 'x'], $uid);

            $claimed = JobQueue::claimNext();
            $this->assertNotNull($claimed);
            $this->assertSame('running', $claimed['status']);
        } finally {
            $db->rollBack();
        }
    }
}
