<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class UserApiTest extends TestCase
{
    /** @var int Fixture admin user id, shared across tests in this class */
    private static int $adminId;

    /** @var string Fixed CSRF token for all POST calls in this class */
    private static string $csrf = 'test-csrf-token-fixed-1234567890ab';

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $hash = password_hash('AdminPass1234!', PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare(
            "INSERT INTO users (username, password_hash, display_name, role, must_change_password, active) VALUES ('apitest_fixture', ?, 'API Test Admin', 'admin', 0, 1)"
        )->execute([$hash]);
        self::$adminId = (int)$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $db = getDB();
        // Remove fixture user and any users created by tests (cleanup by prefix)
        $db->prepare("DELETE FROM users WHERE username = 'apitest_fixture'")->execute();
        $db->prepare("DELETE FROM users WHERE username LIKE 'apitestcreated%'")->execute();
        // Clean up audit_log rows for the fixture admin (keep DB tidy)
        $db->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([self::$adminId]);
    }

    /**
     * Drive a call to the users handler directly (plain require, re-runnable per call).
     *
     * WHY directly require the handler rather than going through system.php:
     * system.php uses `require_once` for the handler file. In a single PHPUnit process,
     * require_once means the handler's top-level switch body executes ONLY on the first
     * call — subsequent calls through the router produce no output. Requiring the handler
     * file with a plain `require` bypasses this limitation so each test call re-executes
     * the switch. The router gate (requireAdmin) is separately covered by
     * testUnauthenticatedRequestRejected (live HTTP) and AuthTest (unit).
     *
     * CSRF: requireCsrfOrAbort() reads $_POST['_csrf'] and compares to $_SESSION['csrf']
     * via hash_equals. We set both to self::$csrf so POST calls pass the gate.
     *
     * @param string $method  'GET' or 'POST'
     * @param string $op      value of ?op=
     * @param array  $params  additional GET or POST params
     * @return array decoded JSON response
     */
    private function adminCurl(string $method, string $op, array $params = []): array
    {
        // Start the session first so subsequent startSessionOnce() calls inside the
        // handler (triggered by requireCsrfOrAbort/csrfVerify) find it already active
        // and do not re-start it (which would overwrite our pre-set $_SESSION values
        // by loading a stale session file).
        startSessionOnce();

        // Wire session: set the fixture admin as current user
        $_SESSION['user'] = [
            'id'   => self::$adminId,
            'username' => 'apitest_fixture',
            'display_name' => 'API Test Admin',
            'role' => 'admin',
            'must_change_password' => false,
        ];
        // Wire CSRF token in session so requireCsrfOrAbort() finds it
        $_SESSION['csrf'] = self::$csrf;

        $_SERVER['REQUEST_METHOD'] = $method;

        if ($method === 'GET') {
            $_GET  = array_merge(['action' => 'users', 'op' => $op], $params);
            $_POST = [];
        } else {
            // POST: include _csrf so requireCsrfOrAbort() passes
            $_GET  = ['action' => 'users'];
            $_POST = array_merge(['op' => $op, '_csrf' => self::$csrf], $params);
        }

        ob_start();
        try {
            // Require the handler directly (plain require = re-runnable, see docblock).
            require __DIR__ . '/../../public_html/api/system/users.php';
        } catch (\Throwable $e) {
            // In test mode jsonResponse() does NOT exit, so no throw expected here;
            // catch just in case of unexpected errors (e.g. legacy code path).
        }
        $raw = ob_get_clean();

        // Reset superglobals to avoid cross-test contamination
        $_GET  = [];
        $_POST = [];

        // In test mode jsonResponse() echoes but does NOT exit, so switch cases can fall
        // through and produce multiple concatenated JSON objects. We want only the FIRST
        // (the one that would have been the exit point in web mode). Find it by tracking
        // brace depth to locate the end of the first JSON object.
        $first = self::extractFirstJson($raw ?: '');
        $decoded = json_decode($first, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Extract the first complete JSON object from a string that may contain multiple
     * concatenated JSON objects (happens in test mode because jsonResponse() echoes
     * but does not exit, so switch fall-through produces multiple outputs).
     */
    private static function extractFirstJson(string $raw): string
    {
        $depth = 0;
        $inString = false;
        $escape = false;
        $start = false;
        for ($i = 0, $len = strlen($raw); $i < $len; $i++) {
            $ch = $raw[$i];
            if ($start === false && $ch === '{') {
                $start = $i;
            }
            if ($start === false) continue;
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($ch === '\\' && $inString) {
                $escape = true;
                continue;
            }
            if ($ch === '"') {
                $inString = !$inString;
                continue;
            }
            if (!$inString) {
                if ($ch === '{') $depth++;
                elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($raw, $start, $i - $start + 1);
                    }
                }
            }
        }
        return $raw; // fallback: return full string if no complete object found
    }

    // -------------------------------------------------------------------------
    // Existing test — must remain as-is (live HTTP, unauthenticated)
    // -------------------------------------------------------------------------

    public function testUnauthenticatedRequestRejected(): void
    {
        // Direct curl to the router endpoint (assumes vhost up)
        $url = 'https://developers.soritune.com/api/system.php?action=auth&op=me';
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'Accept: application/json']]);
        $resp = file_get_contents($url, false, $ctx);
        $code = (int)preg_replace('/^HTTP\/[\d.]+ (\d+).*/', '$1', $http_response_header[0] ?? 'HTTP/1.1 500');
        $this->assertContains($code, [401, 302, 303], "Expected redirect/401, got $code");
    }

    // -------------------------------------------------------------------------
    // New CRUD tests
    // -------------------------------------------------------------------------

    public function testListUsersReturnsArray(): void
    {
        $r = $this->adminCurl('GET', 'list');
        $this->assertTrue($r['ok'] ?? false, 'Expected ok=true, got: ' . json_encode($r));
        $this->assertArrayHasKey('users', $r);
        $this->assertIsArray($r['users']);
        // The fixture admin we inserted must appear in the list
        $found = false;
        foreach ($r['users'] as $u) {
            if ((int)$u['id'] === self::$adminId) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Fixture admin not found in user list');
    }

    public function testCreateRequiresValidUsername(): void
    {
        // Bad username: starts with digit
        $r = $this->adminCurl('POST', 'create', [
            'username'     => '1badname',
            'display_name' => 'Bad User',
            'role'         => 'employee',
            'temp_password' => 'ValidPass1234!',
        ]);
        $this->assertFalse($r['ok'] ?? true, 'Expected ok=false for invalid username');
        $this->assertStringContainsString('username', $r['message'] ?? '');
    }

    public function testCreateThenResetThenDeactivate(): void
    {
        $db = getDB();
        $newUsername = 'apitestcreated' . bin2hex(random_bytes(3));

        // --- CREATE ---
        $r = $this->adminCurl('POST', 'create', [
            'username'      => $newUsername,
            'display_name'  => 'Created User',
            'role'          => 'employee',
            'temp_password' => 'TempPass5678abc',
        ]);
        $this->assertTrue($r['ok'] ?? false, 'Create failed: ' . json_encode($r));
        $this->assertSame('created', $r['message'] ?? '');
        $newId = (int)($r['user']['id'] ?? 0);
        $this->assertGreaterThan(0, $newId, 'Expected a positive new user id');

        // Verify row in DB
        $st = $db->prepare("SELECT username, role, active, must_change_password FROM users WHERE id = ?");
        $st->execute([$newId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($newUsername, $row['username']);
        $this->assertSame('employee', $row['role']);
        $this->assertSame(1, (int)$row['active']);
        $this->assertSame(1, (int)$row['must_change_password']);

        // --- RESET PASSWORD ---
        $r2 = $this->adminCurl('POST', 'reset_password', [
            'user_id'      => (string)$newId,
            'new_password' => 'NewPass9999xyz!',
        ]);
        $this->assertTrue($r2['ok'] ?? false, 'Reset password failed: ' . json_encode($r2));
        $this->assertSame('reset', $r2['message'] ?? '');

        // Verify new hash verifies
        $st2 = $db->prepare("SELECT password_hash, must_change_password, failed_attempts, locked_until FROM users WHERE id = ?");
        $st2->execute([$newId]);
        $row2 = $st2->fetch(PDO::FETCH_ASSOC);
        $this->assertTrue(password_verify('NewPass9999xyz!', $row2['password_hash']), 'Password hash not updated');
        $this->assertSame(1, (int)$row2['must_change_password']);
        $this->assertSame(0, (int)$row2['failed_attempts']);
        $this->assertNull($row2['locked_until']);

        // --- SET ACTIVE = 0 (deactivate) ---
        $r3 = $this->adminCurl('POST', 'set_active', [
            'user_id' => (string)$newId,
            'active'  => '0',
        ]);
        $this->assertTrue($r3['ok'] ?? false, 'Deactivate failed: ' . json_encode($r3));
        $this->assertSame('deactivated', $r3['message'] ?? '');

        $st3 = $db->prepare("SELECT active FROM users WHERE id = ?");
        $st3->execute([$newId]);
        $row3 = $st3->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(0, (int)$row3['active']);

        // --- SET GITHUB USERNAME ---
        $r4 = $this->adminCurl('POST', 'set_github_username', [
            'user_id'         => (string)$newId,
            'github_username' => 'created-gh',
        ]);
        $this->assertTrue($r4['ok'] ?? false, 'set_github_username failed: ' . json_encode($r4));
        $st4 = $db->prepare("SELECT github_username FROM users WHERE id = ?");
        $st4->execute([$newId]);
        $this->assertSame('created-gh', $st4->fetchColumn());

        // --- CLEANUP: delete the created user ---
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$newId]);
        $db->prepare("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id = ?")->execute([$newId]);
    }

    public function testCreateDuplicateUsernameReturns409(): void
    {
        $db = getDB();
        $username = 'apitestcreated' . bin2hex(random_bytes(3));
        $params = [
            'username'      => $username,
            'display_name'  => 'Dup User',
            'role'          => 'employee',
            'temp_password' => 'TempPass5678abc',
        ];
        $first = $this->adminCurl('POST', 'create', $params);
        $this->assertTrue($first['ok'] ?? false, 'First create failed: ' . json_encode($first));
        $newId = (int)($first['user']['id'] ?? 0);

        // Second create with same username must be rejected (409).
        $dup = $this->adminCurl('POST', 'create', $params);
        $this->assertFalse($dup['ok'] ?? true, 'Expected duplicate to be rejected');
        $this->assertStringContainsString('already exists', $dup['message'] ?? '');

        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$newId]);
        $db->prepare("DELETE FROM audit_log WHERE entity_type = 'user' AND entity_id = ?")->execute([$newId]);
    }

    public function testPostWithBadCsrfRejected(): void
    {
        // Drive the handler with a mismatched _csrf token; requireCsrfOrAbort() must abort.
        startSessionOnce();
        $_SESSION['user'] = [
            'id' => self::$adminId, 'username' => 'apitest_fixture',
            'display_name' => 'API Test Admin', 'role' => 'admin', 'must_change_password' => false,
        ];
        $_SESSION['csrf'] = self::$csrf;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['action' => 'users'];
        $_POST = ['op' => 'create', '_csrf' => 'WRONG-token', 'username' => 'apitestcreatedx', 'display_name' => 'X', 'role' => 'employee', 'temp_password' => 'TempPass5678abc'];

        ob_start();
        try {
            require __DIR__ . '/../../public_html/api/system/users.php';
        } catch (\Throwable $e) {
            // no throw expected in test mode
        }
        $raw = ob_get_clean();
        $_GET = [];
        $_POST = [];

        $decoded = json_decode(self::extractFirstJson($raw ?: ''), true) ?: [];
        // The FIRST response emitted is the CSRF error — this is what reaches the client.
        // (In web mode jsonError() exits here, halting the request before the switch runs.
        // In test mode jsonResponse() does not exit, so the create still executes after the
        // error is emitted; we therefore can't assert non-creation in-process — we just
        // verify the abort response and clean up any row the in-process fallthrough created.)
        $this->assertFalse($decoded['ok'] ?? true, 'Expected CSRF abort response');
        $this->assertStringContainsString('csrf', strtolower($decoded['message'] ?? ''));

        getDB()->prepare("DELETE FROM users WHERE username = 'apitestcreatedx'")->execute();
    }
}
