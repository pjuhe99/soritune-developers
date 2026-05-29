<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\CliRunner;

final class CliRunnerTest extends TestCase
{
    public function testRealEcho(): void
    {
        $r = (new CliRunner())->run('echo hi');
        $this->assertSame(0, $r['code']);
        $this->assertSame('hi', trim($r['out']));
    }
    public function testNonZero(): void
    {
        $r = (new CliRunner())->run('exit 3');
        $this->assertSame(3, $r['code']);
    }
    public function testFakeInjection(): void
    {
        $runner = new CliRunner(fn($cmd) => ['code'=>0,'out'=>"FAKE:$cmd",'err'=>'']);
        $this->assertSame('FAKE:whatever', $runner->run('whatever')['out']);
    }
}
