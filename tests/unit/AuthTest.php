<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testGetUserReturnsNullWhenAnon(): void
    {
        $this->assertNull(currentUser());
    }

    public function testIsAdminFalseWhenAnon(): void
    {
        $this->assertFalse(isAdmin());
    }

    public function testIsAdminTrueWhenAdminSet(): void
    {
        $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
        $this->assertTrue(isAdmin());
    }

    public function testIsEmployeeTrueWhenEmployeeSet(): void
    {
        $_SESSION['user'] = ['id' => 2, 'username' => 'alice', 'role' => 'employee'];
        $this->assertTrue(isEmployee());
        $this->assertFalse(isAdmin());
    }

    public function testCurrentUserReturnsSessionShape(): void
    {
        $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
        $u = currentUser();
        $this->assertSame(1, $u['id']);
        $this->assertSame('admin', $u['role']);
    }
}
