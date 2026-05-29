<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\CliRunner;
use Soritune\Developers\Route53;

final class Route53Test extends TestCase
{
    public function testUpsertBuildsChangeBatch(): void
    {
        $seen = '';
        $r53 = new Route53('3.37.213.224', new CliRunner(function ($cmd) use (&$seen) {
            $seen = $cmd;
            if (str_contains($cmd, 'list-hosted-zones')) {
                return ['code'=>0,'out'=>json_encode(['HostedZones'=>[['Id'=>'/hostedzone/Z123','Name'=>'soritune.com.']]]),'err'=>''];
            }
            return ['code'=>0,'out'=>json_encode(['ChangeInfo'=>['Id'=>'/change/C1','Status'=>'PENDING']]),'err'=>''];
        }));
        $r = $r53->upsertA('camp-dev.soritune.com');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertStringContainsString('change-resource-record-sets', $seen);
        $this->assertStringContainsString('Z123', $seen);
    }

    public function testZoneNotFound(): void
    {
        $r53 = new Route53('3.37.213.224', new CliRunner(fn($cmd) =>
            ['code'=>0,'out'=>json_encode(['HostedZones'=>[]]),'err'=>'']));
        $r = $r53->upsertA('camp-dev.soritune.com');
        $this->assertFalse($r['ok']);
    }
}
