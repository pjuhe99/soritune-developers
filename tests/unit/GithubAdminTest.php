<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\CliRunner;
use Soritune\Developers\GithubAdmin;

final class GithubAdminTest extends TestCase
{
    private function ga(callable $fake): GithubAdmin
    {
        return new GithubAdmin('TESTTOKEN', 'acct', 'user', new CliRunner($fake));
    }

    public function testCreateRepoNew(): void
    {
        $ga = $this->ga(function ($cmd) {
            return ['code'=>0,'out'=>json_encode(['full_name'=>'acct/camp','html_url'=>'https://github.com/acct/camp']),'err'=>''];
        });
        $r = $ga->createRepo('camp', 'desc');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['created']);
        $this->assertSame('acct/camp', $r['full_name']);
    }

    public function testCreateRepoExistsRecovers(): void
    {
        $ga = $this->ga(function ($cmd) {
            // POST fails (422 exists); GET /repos/acct/camp recovers, owned by acct
            if (str_contains($cmd, 'GET') && str_contains($cmd, 'repos/acct/camp')) {
                return ['code'=>0,'out'=>json_encode(['full_name'=>'acct/camp','html_url'=>'https://github.com/acct/camp','owner'=>['login'=>'acct']]),'err'=>''];
            }
            return ['code'=>1,'out'=>'HTTP 422 name already exists','err'=>''];
        });
        $r = $ga->createRepo('camp', 'desc');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($r['created']);
        $this->assertSame('acct/camp', $r['full_name']);
    }

    public function testCreateRepoExistsOtherOwnerFails(): void
    {
        $ga = $this->ga(function ($cmd) {
            if (str_contains($cmd, 'GET') && str_contains($cmd, 'repos/acct/camp')) {
                return ['code'=>0,'out'=>json_encode(['full_name'=>'other/camp','owner'=>['login'=>'other']]),'err'=>''];
            }
            return ['code'=>1,'out'=>'HTTP 422 name already exists','err'=>''];
        });
        $r = $ga->createRepo('camp', 'desc');
        $this->assertFalse($r['ok']);
    }

    public function testAddRulesetsTwo(): void
    {
        $seen = [];
        $ga = $this->ga(function ($cmd) use (&$seen) {
            $seen[] = $cmd;
            return ['code'=>0,'out'=>json_encode(['id'=>count($seen)]),'err'=>''];
        });
        $r = $ga->addRulesets('acct/camp');
        $this->assertTrue($r['ok']);
        $this->assertCount(2, $r['ruleset_ids']);
    }

    public function testAddRulesetsFailIsFatal(): void
    {
        $ga = $this->ga(fn($cmd) => ['code'=>1,'out'=>'forbidden','err'=>'']);
        $r = $ga->addRulesets('acct/camp');
        $this->assertFalse($r['ok']);
    }

    public function testAddCollaboratorsSkipsMissingUsername(): void
    {
        $ga = $this->ga(fn($cmd) => ['code'=>0,'out'=>'','err'=>'']);
        $r = $ga->addCollaborators('acct/camp', ['alice', '']);
        $this->assertTrue($r['ok']);
        $this->assertSame(['alice'], $r['added']);
        $this->assertSame([''], $r['skipped']);
    }

    public function testCreateDevBranch(): void
    {
        $ga = $this->ga(function ($cmd) {
            if (str_contains($cmd, 'git/ref/heads/main')) return ['code'=>0,'out'=>'abc123sha','err'=>''];
            return ['code'=>0,'out'=>json_encode(['ref'=>'refs/heads/dev']),'err'=>''];
        });
        $r = $ga->createDevBranch('acct/camp');
        $this->assertTrue($r['ok']);
        $this->assertFalse($r['existed']);
    }

    public function testCreateDevBranchExisted(): void
    {
        $ga = $this->ga(function ($cmd) {
            if (str_contains($cmd, 'git/ref/heads/main')) return ['code'=>0,'out'=>'abc123sha','err'=>''];
            return ['code'=>1,'out'=>'HTTP 422 Reference already exists','err'=>''];
        });
        $r = $ga->createDevBranch('acct/camp');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['existed']);
    }
}
