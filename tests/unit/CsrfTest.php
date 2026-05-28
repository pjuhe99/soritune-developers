<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenGeneratedAndStable(): void
    {
        $t1 = csrfToken();
        $t2 = csrfToken();
        $this->assertSame($t1, $t2);
        $this->assertSame(64, strlen($t1)); // hex32 = 64 chars
    }

    public function testTokenValidatesCorrect(): void
    {
        $t = csrfToken();
        $this->assertTrue(csrfVerify($t));
    }

    public function testTokenRejectsWrong(): void
    {
        csrfToken();
        $this->assertFalse(csrfVerify('garbage'));
    }

    public function testTokenRejectsEmpty(): void
    {
        csrfToken();
        $this->assertFalse(csrfVerify(''));
    }
}
