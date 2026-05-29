<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class LoginTest extends TestCase
{
    private PDO $db;
    private string $username;
    private string $password;

    protected function setUp(): void
    {
        $this->db = getDB();
        // Insert a fixture admin
        $this->username = 'logintest_' . bin2hex(random_bytes(4));
        $this->password = 'TestPass1234567';
        $hash = password_hash($this->password, PASSWORD_BCRYPT, ['cost' => 10]);
        $this->db->prepare(
            "INSERT INTO users (username, password_hash, display_name, role, must_change_password, active) VALUES (?, ?, 'Login Test', 'admin', 0, 1)"
        )->execute([$this->username, $hash]);
    }

    protected function tearDown(): void
    {
        $this->db->prepare("DELETE FROM users WHERE username = ?")->execute([$this->username]);
    }

    public function testVerifyPasswordSuccess(): void
    {
        $row = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $row->execute([$this->username]);
        $u = $row->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify($this->password, $u['password_hash']));
    }

    public function testFailedAttemptsLockoutLogic(): void
    {
        // Helper function `attemptLogin($username, $password)` is in login.php
        // We exercise the helper directly without full HTTP roundtrip.
        require_once __DIR__ . '/../../public_html/login.php';
        for ($i = 0; $i < 5; $i++) {
            $res = attemptLogin($this->username, 'wrong-password');
            $this->assertFalse($res['ok']);
        }
        $u = $this->db->query("SELECT failed_attempts, locked_until FROM users WHERE username = " . $this->db->quote($this->username))->fetch(PDO::FETCH_ASSOC);
        $this->assertGreaterThanOrEqual(5, (int)$u['failed_attempts']);
        $this->assertNotNull($u['locked_until']);

        // Even correct password should fail while locked
        $res = attemptLogin($this->username, $this->password);
        $this->assertFalse($res['ok']);
        $this->assertSame('locked', $res['reason']);
    }

    public function testSuccessfulLoginResetsCounter(): void
    {
        require_once __DIR__ . '/../../public_html/login.php';
        $this->db->prepare("UPDATE users SET failed_attempts = 3 WHERE username = ?")->execute([$this->username]);
        $res = attemptLogin($this->username, $this->password);
        $this->assertTrue($res['ok']);
        $u = $this->db->query("SELECT failed_attempts, last_login_at FROM users WHERE username = " . $this->db->quote($this->username))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$u['failed_attempts']);
        $this->assertNotNull($u['last_login_at']);
    }
}
