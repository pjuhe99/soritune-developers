<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\SiteManagerClient;

final class SiteManagerClientTest extends TestCase
{
    private string $pending; private string $done;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/smc_' . bin2hex(random_bytes(4));
        $this->pending = "$base/pending"; $this->done = "$base/done";
        mkdir($this->pending, 0777, true); mkdir($this->done, 0777, true);
    }
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg(dirname($this->pending)));
    }

    /** Simulate the root cron: read pending, write a done result, remove pending. */
    private function fakeCron(string $result): void
    {
        foreach (glob($this->pending . '/*.json') as $f) {
            $id = basename($f, '.json');
            file_put_contents($this->done . "/$id.json", $result);
            unlink($f);
        }
    }

    public function testRunActionSuccess(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 2, 0);
        $c->setOnEnqueued(fn() => $this->fakeCron('{"success":true,"db_pass":"x"}'));
        $r = $c->runAction('create_folders', 'camp-dev');
        $this->assertTrue($r['ok'], json_encode($r));
    }

    public function testRunActionFailure(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 2, 0);
        $c->setOnEnqueued(fn() => $this->fakeCron('{"success":false,"error":"boom"}'));
        $r = $c->runAction('issue_ssl', 'camp-dev');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('boom', $r['error']);
    }

    public function testRunActionTimeout(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 1, 0);
        $r = $c->runAction('check_conflict', 'camp-dev');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('timeout', $r['error']);
    }

    public function testProvisionSkipsOnConflictExists(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 2, 0);
        $c->setOnEnqueued(function () {
            $this->fakeCron('{"success":false,"error":"already exists","exists":true}');
        });
        $r = $c->provision('camp-dev');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['skipped']);
    }
}
