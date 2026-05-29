<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\SiteCheck;

final class SiteCheckTest extends TestCase
{
    public function testUpHostReturnsCode(): void
    {
        // The portal itself is up; / returns 302 -> login (a real HTTP code).
        $r = SiteCheck::ping('developers.soritune.com');
        $this->assertTrue($r['up'], json_encode($r));
        $this->assertGreaterThanOrEqual(200, $r['code']);
        $this->assertLessThan(600, $r['code']);
    }

    public function testDownHostReturnsUpFalse(): void
    {
        $r = SiteCheck::ping('no-such-host.invalid');
        $this->assertFalse($r['up']);
    }
}
