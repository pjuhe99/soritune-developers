<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class ProjectApiTest extends TestCase
{
    /** @var int Fixture admin user id, shared across tests in this class */
    private static int $adminId;

    /** @var string Fixed CSRF token for all POST calls in this class */
    private static string $csrf = 'test-proj-csrf-token-fixed-1234567890';

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        // Insert fixture admin; password_hash must be NOT NULL (use placeholder 'x')
        $db->prepare(
            "INSERT INTO users (username, password_hash, display_name, role, must_change_password, active)
             VALUES ('projadmin', 'x', 'Project Admin Fixture', 'admin', 0, 1)"
        )->execute();
        self::$adminId = (int)$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $db = getDB();

        // Scope cleanup to projects created BY the fixture admin (not a slug prefix,
        // which could collide with real project slugs like the live 'pt' site).
        $st = $db->prepare("SELECT id FROM projects WHERE created_by = ?");
        $st->execute([self::$adminId]);
        $projectIds = $st->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($projectIds)) {
            // project_access has FK ON DELETE CASCADE from projects, but clean explicitly to be safe
            $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
            $db->prepare("DELETE FROM project_access WHERE project_id IN ($placeholders)")->execute($projectIds);
            $db->prepare("DELETE FROM projects WHERE id IN ($placeholders)")->execute($projectIds);
        }

        // Clean up audit_log rows for the fixture admin
        $db->prepare("DELETE FROM audit_log WHERE user_id = ?")->execute([self::$adminId]);

        // Remove fixture admin and any 'grantemp' employee created during tests
        $db->prepare("DELETE FROM users WHERE username = 'projadmin'")->execute();
        $db->prepare("DELETE FROM users WHERE username = 'grantemp'")->execute();
    }

    /**
     * Drive a call to the projects handler directly (plain require, re-runnable per call).
     *
     * WHY directly require the handler rather than going through system.php:
     * system.php uses `require_once` for the handler file. In a single PHPUnit process,
     * require_once means the handler's top-level switch body executes ONLY on the first
     * call — subsequent calls through the router produce no output. Requiring the handler
     * file with a plain `require` bypasses this limitation so each test call re-executes
     * the switch. The router gate (requireAdmin) is separately covered by other tests.
     *
     * CSRF: requireCsrfOrAbort() reads $_POST['_csrf'] and compares to $_SESSION['csrf']
     * via hash_equals. We set both to self::$csrf so POST calls pass the gate.
     *
     * SESSION: startSessionOnce() is called FIRST before setting $_SESSION values, so that
     * subsequent startSessionOnce() calls inside the handler (triggered by
     * requireCsrfOrAbort) find the session already active and do not re-start it
     * (which would overwrite our pre-set $_SESSION values by loading a stale session file).
     *
     * @param string $op      value of ?op=
     * @param array  $params  additional GET or POST params
     * @param string $method  'GET' or 'POST'
     * @return array decoded JSON response (first JSON object from output)
     */
    private function call(string $op, array $params = [], string $method = 'POST'): array
    {
        // Start the session first so subsequent startSessionOnce() calls inside the
        // handler find it already active and do not re-start it (overwriting our values).
        startSessionOnce();

        // Wire session: set the fixture admin as current user
        $_SESSION['user'] = [
            'id'                  => self::$adminId,
            'username'            => 'projadmin',
            'display_name'        => 'Project Admin Fixture',
            'role'                => 'admin',
            'must_change_password' => false,
        ];
        // Wire CSRF token in session so requireCsrfOrAbort() finds it
        $_SESSION['csrf'] = self::$csrf;

        $_SERVER['REQUEST_METHOD'] = $method;

        if ($method === 'GET') {
            $_GET  = array_merge(['action' => 'projects', 'op' => $op], $params);
            $_POST = [];
        } else {
            // POST: include _csrf so requireCsrfOrAbort() passes
            $_GET  = ['action' => 'projects'];
            $_POST = array_merge(['op' => $op, '_csrf' => self::$csrf], $params);
        }

        ob_start();
        try {
            // Require the handler directly (plain require = re-runnable, see docblock).
            require __DIR__ . '/../../public_html/api/system/projects.php';
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
        $depth    = 0;
        $inString = false;
        $escape   = false;
        $start    = false;
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
    // Tests
    // -------------------------------------------------------------------------

    public function testRegisterValidatesSlug(): void
    {
        // 'Invalid Slug' contains uppercase and space — must fail isValidSlug
        $r = $this->call('register', [
            'slug'           => 'Invalid Slug',
            'name'           => 'Some Project',
            'github_repo'    => 'pjuhe99/some-project',
            'dev_subdomain'  => 'some-dev.soritune.com',
            'prod_subdomain' => 'some.soritune.com',
            'dev_dir'        => '/var/www/html/dev',
            'prod_dir'       => '/var/www/html/prod',
            'dev_db_name'    => 'DEV_DB',
            'prod_db_name'   => 'PROD_DB',
        ]);
        $this->assertFalse($r['ok'] ?? true, 'Expected ok=false for invalid slug, got: ' . json_encode($r));
        $this->assertStringContainsString('slug', $r['message'] ?? '');
    }

    public function testRegisterCreatesProject(): void
    {
        $db = getDB();
        // Use a unique slug with hex suffix; hyphens only (isValidSlug disallows underscores)
        $slug = 'pt-' . bin2hex(random_bytes(4));

        $r = $this->call('register', [
            'slug'           => $slug,
            'name'           => 'Test Project',
            'github_repo'    => 'pjuhe99/test-project',
            'dev_subdomain'  => $slug . '-dev.soritune.com',
            'prod_subdomain' => $slug . '.soritune.com',
            'dev_dir'        => '/var/www/html/dev-dir',
            'prod_dir'       => '/var/www/html/prod-dir',
            'dev_db_name'    => 'DEV_TEST_DB',
            'prod_db_name'   => 'PROD_TEST_DB',
        ]);
        $this->assertTrue($r['ok'] ?? false, 'Expected ok=true for valid register, got: ' . json_encode($r));
        $this->assertArrayHasKey('project', $r);
        $this->assertSame($slug, $r['project']['slug'] ?? null);
        $newId = (int)($r['project']['id'] ?? 0);
        $this->assertGreaterThan(0, $newId, 'Expected a positive project id');

        // Verify row in DB
        $st = $db->prepare("SELECT slug, name, status, created_by FROM projects WHERE id = ?");
        $st->execute([$newId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $this->assertSame($slug, $row['slug']);
        $this->assertSame('Test Project', $row['name']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(self::$adminId, (int)$row['created_by']);
    }

    public function testGrantAndRevokeAccess(): void
    {
        $db = getDB();

        // Register a project to grant access to
        $slug = 'pt-' . bin2hex(random_bytes(4));
        $reg = $this->call('register', [
            'slug'           => $slug,
            'name'           => 'Access Test Project',
            'github_repo'    => 'pjuhe99/access-test',
            'dev_subdomain'  => $slug . '-dev.soritune.com',
            'prod_subdomain' => $slug . '.soritune.com',
            'dev_dir'        => '/var/www/html/access-dev',
            'prod_dir'       => '/var/www/html/access-prod',
            'dev_db_name'    => 'ACCESS_DEV_DB',
            'prod_db_name'   => 'ACCESS_PROD_DB',
        ]);
        $this->assertTrue($reg['ok'] ?? false, 'Register failed: ' . json_encode($reg));
        $pid = (int)($reg['project']['id'] ?? 0);
        $this->assertGreaterThan(0, $pid);

        // Ensure an employee user exists for grant/revoke target
        $existing = $db->prepare("SELECT id FROM users WHERE username = 'grantemp'");
        $existing->execute();
        $grantee = $existing->fetch(PDO::FETCH_ASSOC);
        if ($grantee) {
            $granteeId = (int)$grantee['id'];
        } else {
            $db->prepare(
                "INSERT INTO users (username, password_hash, display_name, role, must_change_password, active)
                 VALUES ('grantemp', 'x', 'Grant Temp Employee', 'employee', 0, 1)"
            )->execute();
            $granteeId = (int)$db->lastInsertId();
        }
        $this->assertGreaterThan(0, $granteeId);

        // --- GRANT ACCESS ---
        $grant = $this->call('grant_access', [
            'project_id' => (string)$pid,
            'user_id'    => (string)$granteeId,
        ]);
        $this->assertTrue($grant['ok'] ?? false, 'grant_access failed: ' . json_encode($grant));
        $this->assertSame('granted', $grant['message'] ?? '');

        // Verify in DB
        $st = $db->prepare("SELECT COUNT(*) FROM project_access WHERE project_id = ? AND user_id = ?");
        $st->execute([$pid, $granteeId]);
        $this->assertSame(1, (int)$st->fetchColumn(), 'Expected project_access row after grant');

        // --- REVOKE ACCESS ---
        $revoke = $this->call('revoke_access', [
            'project_id' => (string)$pid,
            'user_id'    => (string)$granteeId,
        ]);
        $this->assertTrue($revoke['ok'] ?? false, 'revoke_access failed: ' . json_encode($revoke));
        $this->assertSame('revoked', $revoke['message'] ?? '');

        // Verify revoked in DB
        $st2 = $db->prepare("SELECT COUNT(*) FROM project_access WHERE project_id = ? AND user_id = ?");
        $st2->execute([$pid, $granteeId]);
        $this->assertSame(0, (int)$st2->fetchColumn(), 'Expected project_access row deleted after revoke');
    }

    public function testGetReturnsProjectAndMembers(): void
    {
        $slug = 'pt-' . bin2hex(random_bytes(4));
        $reg = $this->call('register', [
            'slug'           => $slug,
            'name'           => 'Get Test Project',
            'github_repo'    => 'pjuhe99/get-test',
            'dev_subdomain'  => $slug . '-dev.soritune.com',
            'prod_subdomain' => $slug . '.soritune.com',
            'dev_dir'        => '/var/www/html/get-dev',
            'prod_dir'       => '/var/www/html/get-prod',
            'dev_db_name'    => 'GET_DEV_DB',
            'prod_db_name'   => 'GET_PROD_DB',
        ]);
        $this->assertTrue($reg['ok'] ?? false, 'Register failed: ' . json_encode($reg));
        $pid = (int)($reg['project']['id'] ?? 0);

        $r = $this->call('get', ['id' => (string)$pid], 'GET');
        $this->assertTrue($r['ok'] ?? false, 'get failed: ' . json_encode($r));
        $this->assertSame($slug, $r['project']['slug'] ?? null);
        $this->assertArrayHasKey('members', $r);
        $this->assertIsArray($r['members']);

        // Missing project → 404 (ok=false)
        $missing = $this->call('get', ['id' => '99999999'], 'GET');
        $this->assertFalse($missing['ok'] ?? true, 'Expected ok=false for missing project');
    }

    public function testRegisterDuplicateSlugReturns409(): void
    {
        $slug = 'pt-' . bin2hex(random_bytes(4));
        $params = [
            'slug'           => $slug,
            'name'           => 'Dup Slug Project',
            'github_repo'    => 'pjuhe99/dup-slug',
            'dev_subdomain'  => $slug . '-dev.soritune.com',
            'prod_subdomain' => $slug . '.soritune.com',
            'dev_dir'        => '/var/www/html/dup-dev',
            'prod_dir'       => '/var/www/html/dup-prod',
            'dev_db_name'    => 'DUP_DEV_DB',
            'prod_db_name'   => 'DUP_PROD_DB',
        ];
        $first = $this->call('register', $params);
        $this->assertTrue($first['ok'] ?? false, 'First register failed: ' . json_encode($first));

        $dup = $this->call('register', $params);
        $this->assertFalse($dup['ok'] ?? true, 'Expected duplicate slug to be rejected');
        $this->assertStringContainsString('already exists', $dup['message'] ?? '');
    }

    public function testArchiveSetsStatus(): void
    {
        $db = getDB();
        $slug = 'pt-' . bin2hex(random_bytes(4));
        $reg = $this->call('register', [
            'slug'           => $slug,
            'name'           => 'Archive Test Project',
            'github_repo'    => 'pjuhe99/archive-test',
            'dev_subdomain'  => $slug . '-dev.soritune.com',
            'prod_subdomain' => $slug . '.soritune.com',
            'dev_dir'        => '/var/www/html/arc-dev',
            'prod_dir'       => '/var/www/html/arc-prod',
            'dev_db_name'    => 'ARC_DEV_DB',
            'prod_db_name'   => 'ARC_PROD_DB',
        ]);
        $pid = (int)($reg['project']['id'] ?? 0);
        $this->assertGreaterThan(0, $pid);

        $arc = $this->call('archive', ['project_id' => (string)$pid]);
        $this->assertTrue($arc['ok'] ?? false, 'archive failed: ' . json_encode($arc));

        $st = $db->prepare("SELECT status FROM projects WHERE id = ?");
        $st->execute([$pid]);
        $this->assertSame('archived', $st->fetchColumn());

        // Archiving a non-existent project → 404
        $missing = $this->call('archive', ['project_id' => '99999999']);
        $this->assertFalse($missing['ok'] ?? true, 'Expected ok=false archiving missing project');
    }
}
