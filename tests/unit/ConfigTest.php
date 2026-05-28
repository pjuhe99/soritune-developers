<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testEEscapes(): void
    {
        $this->assertSame('&lt;script&gt;', e('<script>'));
        $this->assertSame('&quot;', e('"'));
    }

    public function testEAcceptsEmpty(): void
    {
        $this->assertSame('', e(''));
    }

    public function testEMustReceiveStringNotNull(): void
    {
        // Strict signature: nullable use must pass ?? '' (memory php-e-null-strict-signature)
        // expectError() removed in PHPUnit 10; use expectException(\TypeError::class)
        $this->expectException(\TypeError::class);
        e(null);
    }

    public function testJsonSuccessFlattens(): void
    {
        // jsonSuccess merges payload into top-level (memory php-jsonsuccess-flatten-shape)
        ob_start();
        try {
            jsonSuccess(['key' => 'value', 'count' => 3], 'OK');
        } catch (\Throwable $e) {
            // exit() may throw in tests if guarded; that's fine
        }
        $out = ob_get_clean();
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('value', $decoded['key']);
        $this->assertSame(3, $decoded['count']);
        $this->assertSame('OK', $decoded['message']);
    }

    public function testGetDbReturnsPdo(): void
    {
        $db = getDB();
        $this->assertInstanceOf(\PDO::class, $db);
        $row = $db->query("SELECT 1 AS v")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['v']);
    }
}
