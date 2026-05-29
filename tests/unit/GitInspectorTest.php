<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\GitInspector;

final class GitInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        // Build a throwaway git repo: 2 commits on main, then a dev branch +1 commit.
        $this->dir = sys_get_temp_dir() . '/gi_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $g = "git -C " . escapeshellarg($this->dir) . " -c user.email=t@t -c user.name=t -c init.defaultBranch=main";
        shell_exec("$g init -q 2>&1");
        file_put_contents($this->dir . '/a.txt', "1");
        shell_exec("$g add -A 2>&1 && $g commit -q -m first 2>&1");
        file_put_contents($this->dir . '/a.txt', "2");
        shell_exec("$g commit -q -am second 2>&1");
        shell_exec("$g checkout -q -b dev 2>&1");
        file_put_contents($this->dir . '/b.txt', "x");
        shell_exec("$g add -A 2>&1 && $g commit -q -m third 2>&1");
        // leave HEAD on dev
    }

    protected function tearDown(): void
    {
        shell_exec("rm -rf " . escapeshellarg($this->dir));
    }

    public function testInspectReturnsHeadAndLog(): void
    {
        $r = GitInspector::inspect($this->dir);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('dev', $r['branch']);
        $this->assertSame(7, strlen($r['head']));   // short sha
        $this->assertSame('third', $r['subject']);
        $this->assertIsArray($r['log']);
        $this->assertGreaterThanOrEqual(3, count($r['log']));
        $this->assertSame('third', $r['log'][0]['subject']);
    }

    public function testInspectMissingDir(): void
    {
        $r = GitInspector::inspect($this->dir . '_nope');
        $this->assertFalse($r['ok']);
        $this->assertSame('경로 없음', $r['error']);
    }

    public function testInspectNonGitDir(): void
    {
        $plain = sys_get_temp_dir() . '/gi_plain_' . bin2hex(random_bytes(4));
        mkdir($plain);
        try {
            $r = GitInspector::inspect($plain);
            $this->assertFalse($r['ok']);
            $this->assertSame('git 저장소 아님', $r['error']);
        } finally {
            rmdir($plain);
        }
    }

    public function testCountAheadDevAheadOfMain(): void
    {
        $r = GitInspector::countAhead($this->dir, 'main', 'dev');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(1, $r['count']);
        $this->assertSame('third', $r['commits'][0]['subject']);
    }

    public function testCountAheadUnknownBase(): void
    {
        $r = GitInspector::countAhead($this->dir, 'nonexistentbase', 'dev');
        $this->assertFalse($r['ok']);
        $this->assertSame('비교 불가', $r['error']);
    }

    public function testCountAheadUnknownHead(): void
    {
        $r = GitInspector::countAhead($this->dir, 'main', 'nonexistenthead');
        $this->assertFalse($r['ok']);
        $this->assertSame('비교 불가', $r['error']);
    }
}
