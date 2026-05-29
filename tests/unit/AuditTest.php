<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\Audit;
use PDO;

final class AuditTest extends TestCase
{
    public function testWriteInsertsRow(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            // Need a user FK
            $db->exec("INSERT INTO users (username, password_hash, display_name, role) VALUES ('audit_t', '\$2y\$10\$abcd', 'Audit Test', 'admin')");
            $userId = (int)$db->lastInsertId();

            Audit::write($userId, 'test.action', 'user', $userId, ['key' => 'val'], '127.0.0.1');

            $row = $db->query("SELECT * FROM audit_log WHERE action='test.action' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('test.action', $row['action']);
            $this->assertSame('user', $row['entity_type']);
            $this->assertSame($userId, (int)$row['entity_id']);
            $this->assertSame($userId, (int)$row['user_id']);
            $payload = json_decode($row['payload'], true);
            $this->assertSame('val', $payload['key']);
            $this->assertSame('127.0.0.1', $row['ip']);
        } finally {
            $db->rollBack();
        }
    }

    public function testWriteAllowsNullUserForSystem(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            Audit::write(null, 'system.startup', 'system', 0, ['v' => 1], null);
            $row = $db->query("SELECT * FROM audit_log WHERE action='system.startup' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNull($row['user_id']);
            $this->assertNull($row['ip']);
            $payload = json_decode($row['payload'], true);
            $this->assertSame(1, $payload['v']);
        } finally {
            $db->rollBack();
        }
    }
}
