<?php
declare(strict_types=1);
namespace Soritune\Developers;

use PDO;

final class Audit
{
    public static function write(
        ?int $userId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $payload = null,
        ?string $ip = null
    ): void {
        $db = getDB();
        $st = $db->prepare(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, payload, ip)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            $ip,
        ]);
    }

    public static function writeFromRequest(?int $userId, string $action, string $entityType, int $entityId, ?array $payload = null): void
    {
        self::write($userId, $action, $entityType, $entityId, $payload, $_SERVER['REMOTE_ADDR'] ?? null);
    }
}
