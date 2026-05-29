<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\ProjectNaming;

final class ProjectNamingTest extends TestCase
{
    public function testDeriveFromJuniorDevSubdomain(): void
    {
        $n = ProjectNaming::fromSubdomain('dev-j.soritune.com');
        $this->assertSame('dev-j', $n['bare']);
        $this->assertSame('DEV_J', $n['derived']);
        $this->assertSame('/var/www/html/_______site_SORITUNECOM_DEV_J', $n['site_dir']);
        $this->assertSame('/var/www/html/_______site_SORITUNECOM_DEV_J/public_html', $n['code_dir']);
        $this->assertSame('SORITUNECOM_DEV_J', $n['db_name']);
    }

    public function testDeriveFromProdSubdomain(): void
    {
        $n = ProjectNaming::fromSubdomain('j.soritune.com');
        $this->assertSame('j', $n['bare']);
        $this->assertSame('J', $n['derived']);
        $this->assertSame('SORITUNECOM_J', $n['db_name']);
    }

    public function testBareWithoutDotSoritune(): void
    {
        $n = ProjectNaming::fromSubdomain('camp-dev');
        $this->assertSame('camp-dev', $n['bare']);
        $this->assertSame('CAMP_DEV', $n['derived']);
    }

    public function testRejectsInvalid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProjectNaming::fromSubdomain('Bad Subdomain!');
    }
}
