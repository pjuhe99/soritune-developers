# Plan B — 관측 대시보드 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** developers.soritune.com 포털에 각 프로젝트의 git 상태·사이트 가동·미배포 diff·배포 로그를 **읽기 전용으로** 보여주는 관리자 관측 대시보드를 추가한다.

**Architecture:** 포털 PHP(apache)가 프로젝트의 dev/prod 디렉토리에 대해 읽기 전용 git 명령을 직접 실행(`git -C <dir> -c safe.directory=<dir> <readonly>`)하고, dev/prod 서브도메인에 HTTP HEAD 를 날려 가동을 확인한다. git pull/merge/push 는 없다(능동 배포는 Plan C). 결과를 `project_status` JSON API 로 묶어 `admin/project_detail.php` 가 렌더한다.

**Tech Stack:** PHP 8 / MariaDB 10.5 / Apache. composer PSR-4 (`Soritune\Developers\`), phpunit 10, vanilla JS. 기존 패턴: fragment-dispatch 라우터, `jsonSuccess`/`jsonError`(test 모드 exit 안 함 → switch case 마다 `return;`), `e()`/JS `escape()`.

**Spec:** `docs/superpowers/specs/2026-05-29-plan-b-observability-design.md`

---

## File Structure

```
lib/GitInspector.php                         (신규) 읽기 전용 git 조회
lib/SiteCheck.php                            (신규) HTTP HEAD ping
migrations/008_add_deploy_log_path.sql       (신규) projects.deploy_log_path 컬럼
public_html/api/system/project_status.php    (신규) op=get 핸들러
public_html/api/system.php                   (수정) handlerMap 에 project_status 추가
public_html/admin/project_detail.php         (신규) 상세 화면
public_html/admin/projects.php               (수정) 카드에 [상세] 링크
public_html/assets/style.css                 (수정) 상세 스타일 + ?v=3
tests/unit/GitInspectorTest.php              (신규)
tests/unit/SiteCheckTest.php                 (신규)
tests/integration/ProjectStatusApiTest.php   (신규)
```

각 파일 단일 책임: GitInspector=git 읽기만, SiteCheck=HTTP 만, 핸들러=조립, UI=렌더.

---

## Task 1: migration 008 — projects.deploy_log_path 컬럼

**Files:**
- Create: `migrations/008_add_deploy_log_path.sql`

- [ ] **Step 1: Write migration**

`migrations/008_add_deploy_log_path.sql`:
```sql
-- Optional path to a project's deploy log (e.g. /root/deploy.log) for the
-- observability dashboard to tail. NULL = not configured (UI shows "미설정").
ALTER TABLE projects ADD COLUMN deploy_log_path VARCHAR(255) NULL DEFAULT NULL AFTER prod_dir;
```

- [ ] **Step 2: Apply migration (idempotent runner)**

Run: `cd /var/www/html/_______site_SORITUNECOM_DEVELOPERS && sudo -u apache ./scripts/run_migrations.sh`
Expected: `APPLY 008_add_deploy_log_path.sql` then `Done.` (재실행 시 SKIP)

- [ ] **Step 3: Verify column exists**

Run: `set -a; . ./.db_credentials; set +a; mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "SHOW COLUMNS FROM projects LIKE 'deploy_log_path';"`
Expected: 한 행 (deploy_log_path, varchar(255), YES, NULL).

- [ ] **Step 4: Commit**

```bash
git add migrations/008_add_deploy_log_path.sql
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat: migration 008 — projects.deploy_log_path (observability)"
```

---

## Task 2: lib/GitInspector.php — 읽기 전용 git 조회

**Files:**
- Create: `lib/GitInspector.php`
- Test: `tests/unit/GitInspectorTest.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/GitInspectorTest.php`:
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\GitInspector;

final class GitInspectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        // Build a throwaway git repo: 2 commits on main, then a dev branch +1 commit.
        $this->dir = sys_get_temp_dir() . '/gi_' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $g = "git -C " . escapeshellarg($this->dir) . " -c user.email=t@t -c user.name=t -c init.defaultBranch=main";
        shell_exec("$g init -q 2>&1");
        file_put_contents($this->dir . '/a.txt', "1");
        shell_exec("$g add -A 2>&1 && $g commit -q -m first 2>&1");
        file_put_contents($this->dir . '/a.txt', "2");
        shell_exec("$g commit -q -am second 2>&1");
        shell_exec("$g checkout -q -b dev 2>&1");
        file_put_contents($this->dir . '/b.txt', "x");
        shell_exec("$g add -A 2>&1 && $g commit -q -m third 2>&1");
        // leave HEAD on dev
    }

    protected function tearDown(): void
    {
        shell_exec("rm -rf " . escapeshellarg($this->dir));
    }

    public function testInspectReturnsHeadAndLog(): void
    {
        $r = GitInspector::inspect($this->dir);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('dev', $r['branch']);
        $this->assertSame(7, strlen($r['head']));   // short sha
        $this->assertSame('third', $r['subject']);
        $this->assertIsArray($r['log']);
        $this->assertGreaterThanOrEqual(3, count($r['log']));
        $this->assertSame('third', $r['log'][0]['subject']);
    }

    public function testInspectMissingDir(): void
    {
        $r = GitInspector::inspect($this->dir . '_nope');
        $this->assertFalse($r['ok']);
        $this->assertSame('경로 없음', $r['error']);
    }

    public function testInspectNonGitDir(): void
    {
        $plain = sys_get_temp_dir() . '/gi_plain_' . bin2hex(random_bytes(4));
        mkdir($plain);
        try {
            $r = GitInspector::inspect($plain);
            $this->assertFalse($r['ok']);
            $this->assertSame('git 저장소 아님', $r['error']);
        } finally {
            rmdir($plain);
        }
    }

    public function testCountAheadDevAheadOfMain(): void
    {
        // dev is 1 commit ahead of main
        $r = GitInspector::countAhead($this->dir, 'main', 'dev');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame(1, $r['count']);
        $this->assertSame('third', $r['commits'][0]['subject']);
    }

    public function testCountAheadUnknownBase(): void
    {
        $r = GitInspector::countAhead($this->dir, 'nonexistentbase', 'dev');
        $this->assertFalse($r['ok']);
        $this->assertSame('비교 불가', $r['error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /var/www/html/_______site_SORITUNECOM_DEVELOPERS && ./vendor/bin/phpunit --filter GitInspectorTest 2>&1 | tail -8`
Expected: FAIL — class `Soritune\Developers\GitInspector` not found.

- [ ] **Step 3: Write minimal implementation**

`lib/GitInspector.php`:
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

/**
 * Read-only git inspection. NEVER runs a writing subcommand
 * (no pull/fetch/merge/checkout/reset/push). All calls go through run()
 * which hardcodes `-c safe.directory=<dir>` to avoid dubious-ownership errors.
 */
final class GitInspector
{
    /** Run a read-only git subcommand in $dir; returns trimmed stdout or null on failure. */
    private static function run(string $dir, array $args): ?string
    {
        $cmd = 'git -C ' . escapeshellarg($dir)
             . ' -c safe.directory=' . escapeshellarg($dir);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg($a);
        }
        $cmd .= ' 2>/dev/null';
        $out = shell_exec($cmd);
        return $out === null ? null : trim($out);
    }

    public static function inspect(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['ok' => false, 'error' => '경로 없음'];
        }
        if (!is_dir($dir . '/.git')) {
            return ['ok' => false, 'error' => 'git 저장소 아님'];
        }
        $head = self::run($dir, ['rev-parse', '--short', 'HEAD']);
        if ($head === null || $head === '') {
            return ['ok' => false, 'error' => 'git 읽기 실패'];
        }
        $branch  = self::run($dir, ['rev-parse', '--abbrev-ref', 'HEAD']) ?? '';
        // %s subject, %an author, %cI committer ISO date — unit separator \x1f
        $top = self::run($dir, ['log', '-1', '--pretty=%s%x1f%an%x1f%cI']) ?? '';
        [$subject, $author, $date] = array_pad(explode("\x1f", $top), 3, '');
        $log = [];
        $raw = self::run($dir, ['log', '-10', '--pretty=%h%x1f%s%x1f%cI']) ?? '';
        foreach (array_filter(explode("\n", $raw)) as $line) {
            [$sha, $sub, $d] = array_pad(explode("\x1f", $line), 3, '');
            $log[] = ['sha' => $sha, 'subject' => $sub, 'date' => $d];
        }
        return [
            'ok' => true, 'head' => $head, 'branch' => $branch,
            'subject' => $subject, 'author' => $author, 'date' => $date, 'log' => $log,
        ];
    }

    /** Commits in $head not yet in $base (e.g. base=main, head=dev = "미배포"). */
    public static function countAhead(string $dir, string $base, string $head): array
    {
        if (!is_dir($dir . '/.git')) {
            return ['ok' => false, 'error' => 'git 저장소 아님'];
        }
        // Resolve base locally, else fall back to origin/<base>.
        $baseRef = $base;
        if (self::run($dir, ['rev-parse', '--verify', '--quiet', $base]) === null
            || self::run($dir, ['rev-parse', '--verify', '--quiet', $base]) === '') {
            $alt = 'origin/' . $base;
            $ok = self::run($dir, ['rev-parse', '--verify', '--quiet', $alt]);
            if ($ok === null || $ok === '') {
                return ['ok' => false, 'error' => '비교 불가'];
            }
            $baseRef = $alt;
        }
        // Verify head ref too.
        $headOk = self::run($dir, ['rev-parse', '--verify', '--quiet', $head]);
        if ($headOk === null || $headOk === '') {
            return ['ok' => false, 'error' => '비교 불가'];
        }
        $range = $baseRef . '..' . $head;
        $count = self::run($dir, ['rev-list', '--count', $range]);
        if ($count === null) {
            return ['ok' => false, 'error' => '비교 불가'];
        }
        $commits = [];
        $raw = self::run($dir, ['log', '--pretty=%h%x1f%s', $range]) ?? '';
        foreach (array_filter(explode("\n", $raw)) as $line) {
            [$sha, $sub] = array_pad(explode("\x1f", $line), 2, '');
            $commits[] = ['sha' => $sha, 'subject' => $sub];
        }
        return ['ok' => true, 'count' => (int)$count, 'commits' => $commits];
    }
}
```

- [ ] **Step 4: dump-autoload + run test to verify pass**

Run: `composer dump-autoload && ./vendor/bin/phpunit --filter GitInspectorTest 2>&1 | tail -8`
Expected: PASS (5 tests).

- [ ] **Step 5: Confirm no writing subcommands present**

Run: `grep -nE "pull|fetch|merge|checkout|reset|push|commit|clone" lib/GitInspector.php`
Expected: 코드에 위 단어 없음 (주석의 'no pull/...' 설명 줄만 — 그 줄은 OK).

- [ ] **Step 6: Fix ownership + commit**

```bash
chown ec2-user:apache lib/GitInspector.php tests/unit/GitInspectorTest.php && chmod 664 lib/GitInspector.php tests/unit/GitInspectorTest.php
git add lib/GitInspector.php tests/unit/GitInspectorTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat: GitInspector — read-only git inspect + countAhead"
```

---

## Task 3: lib/SiteCheck.php — HTTP HEAD ping

**Files:**
- Create: `lib/SiteCheck.php`
- Test: `tests/unit/SiteCheckTest.php`

- [ ] **Step 1: Write the failing test**

`tests/unit/SiteCheckTest.php`:
```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter SiteCheckTest 2>&1 | tail -6`
Expected: FAIL — class not found.

- [ ] **Step 3: Write minimal implementation**

`lib/SiteCheck.php`:
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class SiteCheck
{
    /**
     * HTTP(S) HEAD with a short timeout. Returns {up, code?}.
     * Timeout/DNS failure => up=false (treated as "응답 없음", not a hard "down").
     */
    public static function ping(string $host, int $timeout = 5): array
    {
        $host = trim($host);
        if ($host === '') {
            return ['up' => false];
        }
        $ch = curl_init('https://' . $host . '/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,            // HEAD
            CURLOPT_FOLLOWLOCATION => false,   // report the raw code (302 etc.)
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($errno !== 0 || $code === 0) {
            return ['up' => false];
        }
        return ['up' => true, 'code' => $code];
    }
}
```

- [ ] **Step 4: Run test to verify pass**

Run: `composer dump-autoload && ./vendor/bin/phpunit --filter SiteCheckTest 2>&1 | tail -6`
Expected: PASS (2 tests). (testDownHost 는 DNS 실패로 빠르게 up=false.)

- [ ] **Step 5: Fix ownership + commit**

```bash
chown ec2-user:apache lib/SiteCheck.php tests/unit/SiteCheckTest.php && chmod 664 lib/SiteCheck.php tests/unit/SiteCheckTest.php
git add lib/SiteCheck.php tests/unit/SiteCheckTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat: SiteCheck — HTTP HEAD ping with timeout"
```

---

## Task 4: api/system/project_status.php + 라우터 등록 + 통합 테스트

**Files:**
- Create: `public_html/api/system/project_status.php`
- Modify: `public_html/api/system.php` (handlerMap 에 한 줄)
- Test: `tests/integration/ProjectStatusApiTest.php`

- [ ] **Step 1: Write the failing integration test**

`tests/integration/ProjectStatusApiTest.php`:
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class ProjectStatusApiTest extends TestCase
{
    private static int $adminId;
    private static int $projectId;
    private static string $devDir;
    private static string $prodDir;
    private static string $csrf = 'test-pstatus-csrf-1234567890';

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO users (username,password_hash,display_name,role,must_change_password,active) VALUES ('pstatadmin','x','PStat','admin',0,1)")->execute();
        self::$adminId = (int)$db->lastInsertId();

        // Two throwaway git repos to act as dev/prod dirs.
        self::$devDir  = sys_get_temp_dir() . '/ps_dev_'  . bin2hex(random_bytes(4));
        self::$prodDir = sys_get_temp_dir() . '/ps_prod_' . bin2hex(random_bytes(4));
        foreach ([self::$devDir, self::$prodDir] as $d) {
            mkdir($d);
            $g = "git -C " . escapeshellarg($d) . " -c user.email=t@t -c user.name=t -c init.defaultBranch=main";
            shell_exec("$g init -q 2>&1");
            file_put_contents($d . '/a.txt', '1');
            shell_exec("$g add -A 2>&1 && $g commit -q -m init 2>&1");
        }
        // dev gets an extra branch+commit so 'main..dev' = 1
        $g = "git -C " . escapeshellarg(self::$devDir) . " -c user.email=t@t -c user.name=t";
        shell_exec("$g checkout -q -b dev 2>&1");
        file_put_contents(self::$devDir . '/b.txt', 'x');
        shell_exec("$g add -A 2>&1 && $g commit -q -m feature 2>&1");

        $slug = 'pstat' . bin2hex(random_bytes(3));
        $st = $db->prepare("INSERT INTO projects (slug,name,github_repo,dev_subdomain,prod_subdomain,dev_dir,prod_dir,dev_db_name,prod_db_name,status,created_by) VALUES (?,?,?,?,?,?,?,?,?, 'active', ?)");
        $st->execute([$slug,'PStat Project','org/'.$slug,$slug.'-dev.soritune.com',$slug.'.soritune.com',self::$devDir,self::$prodDir,'D','P',self::$adminId]);
        self::$projectId = (int)$db->lastInsertId();
    }

    public static function tearDownAfterClass(): void
    {
        $db = getDB();
        $db->prepare("DELETE FROM projects WHERE id=?")->execute([self::$projectId]);
        $db->prepare("DELETE FROM users WHERE id=?")->execute([self::$adminId]);
        shell_exec("rm -rf " . escapeshellarg(self::$devDir) . " " . escapeshellarg(self::$prodDir));
    }

    private function call(int $id): array
    {
        startSessionOnce();
        $_SESSION['user'] = ['id'=>self::$adminId,'username'=>'pstatadmin','display_name'=>'PStat','role'=>'admin'];
        $_SESSION['csrf'] = self::$csrf;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET = ['action'=>'project_status','op'=>'get','id'=>(string)$id];
        $_POST = [];
        ob_start();
        try { require __DIR__ . '/../../public_html/api/system/project_status.php'; }
        catch (\Throwable $e) {}
        $raw = ob_get_clean();
        $_GET = []; $_POST = [];
        $decoded = json_decode($this->firstJson($raw), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function firstJson(string $raw): string
    {
        $d=0;$start=false;$in=false;$esc=false;
        for($i=0,$n=strlen($raw);$i<$n;$i++){$c=$raw[$i];
            if($start===false&&$c==='{')$start=$i;
            if($start===false)continue;
            if($esc){$esc=false;continue;}
            if($c==='\\'&&$in){$esc=true;continue;}
            if($c==='"'){$in=!$in;continue;}
            if(!$in){if($c==='{')$d++;elseif($c==='}'){$d--;if($d===0)return substr($raw,$start,$i-$start+1);}}
        }
        return $raw;
    }

    public function testStatusReturnsAllSections(): void
    {
        $r = $this->call(self::$projectId);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));
        $this->assertTrue($r['dev']['ok'] ?? false, 'dev inspect');
        $this->assertSame('dev', $r['dev']['branch'] ?? null);
        $this->assertTrue($r['prod']['ok'] ?? false, 'prod inspect');
        $this->assertSame(1, $r['undeployed']['count'] ?? null, 'undeployed count');
        $this->assertArrayHasKey('sites', $r);
        $this->assertArrayHasKey('dev', $r['sites']);
        $this->assertArrayHasKey('prod', $r['sites']);
        $this->assertArrayHasKey('log', $r);
    }

    public function testUnknownProjectReturns404Shape(): void
    {
        $r = $this->call(99999999);
        $this->assertFalse($r['ok'] ?? true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit --filter ProjectStatusApiTest 2>&1 | tail -8`
Expected: FAIL — handler file missing (require fatal swallowed → empty array → assertions fail).

- [ ] **Step 3: Write the handler**

`public_html/api/system/project_status.php`:
```php
<?php
declare(strict_types=1);
// requireAdmin() already called by api/system.php router.

use Soritune\Developers\GitInspector;
use Soritune\Developers\SiteCheck;

$op = $_GET['op'] ?? $_POST['op'] ?? '';

// Each case ends with return; (jsonResponse does not exit under APP_ENV=test).
switch ($op) {
    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) { jsonError('id required'); return; }
        $st = getDB()->prepare("SELECT * FROM projects WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) { jsonError('not found', 404); return; }

        $dev  = GitInspector::inspect($p['dev_dir']);
        $prod = GitInspector::inspect($p['prod_dir']);
        // 미배포 = dev_dir 기준 main..dev
        $undeployed = GitInspector::countAhead($p['dev_dir'], 'main', 'dev');
        $sites = [
            'dev'  => SiteCheck::ping($p['dev_subdomain']),
            'prod' => SiteCheck::ping($p['prod_subdomain']),
        ];
        $log = ['ok' => false, 'error' => '미설정'];
        $lp = $p['deploy_log_path'] ?? null;
        if ($lp !== null && $lp !== '' && is_file($lp) && is_readable($lp)) {
            $lines = @file($lp, FILE_IGNORE_NEW_LINES);
            if ($lines !== false) {
                $log = ['ok' => true, 'lines' => array_slice($lines, -20)];
            }
        }
        jsonSuccess([
            'project'    => ['id'=>(int)$p['id'],'slug'=>$p['slug'],'name'=>$p['name'],
                             'github_repo'=>$p['github_repo'],'dev_dir'=>$p['dev_dir'],'prod_dir'=>$p['prod_dir'],
                             'dev_subdomain'=>$p['dev_subdomain'],'prod_subdomain'=>$p['prod_subdomain']],
            'dev'        => $dev,
            'prod'       => $prod,
            'undeployed' => $undeployed,
            'sites'      => $sites,
            'log'        => $log,
        ]);
        return;
    }
    default:
        jsonError("unknown op: $op", 404);
        return;
}
```

- [ ] **Step 4: Register in router**

Modify `public_html/api/system.php` — add one line to `$handlerMap`:
```php
$handlerMap = [
    'auth'           => __DIR__ . '/system/auth.php',
    'users'          => __DIR__ . '/system/users.php',
    'projects'       => __DIR__ . '/system/projects.php',
    'jobs'           => __DIR__ . '/system/jobs.php',
    'project_status' => __DIR__ . '/system/project_status.php',
];
```
(나머지 라우터 코드는 그대로. `file_exists` 가드가 이미 있으므로 안전.)

- [ ] **Step 5: Run test to verify pass + full suite**

Run: `./vendor/bin/phpunit --filter ProjectStatusApiTest 2>&1 | tail -8 && ./vendor/bin/phpunit 2>&1 | tail -3`
Expected: ProjectStatusApiTest 2 PASS; 전체 green (기존 + 신규).

- [ ] **Step 6: Live smoke (unauth gate)**

Run: `curl -s -o /dev/null -w '%{http_code}\n' "https://developers.soritune.com/api/system.php?action=project_status&op=get&id=1"`
Expected: `401` (requireAdmin 게이트).

- [ ] **Step 7: Fix ownership + commit**

```bash
chown ec2-user:apache public_html/api/system/project_status.php public_html/api/system.php tests/integration/ProjectStatusApiTest.php && chmod 664 public_html/api/system/project_status.php public_html/api/system.php tests/integration/ProjectStatusApiTest.php
git add public_html/api/system/project_status.php public_html/api/system.php tests/integration/ProjectStatusApiTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat: project_status API (git/site/undeployed/log) + router registration"
```

---

## Task 5: admin/project_detail.php + projects.php 카드 링크 + CSS

**Files:**
- Create: `public_html/admin/project_detail.php`
- Modify: `public_html/admin/projects.php` (카드에 [상세] 링크)
- Modify: `public_html/assets/style.css` (상세 스타일 append + ?v=3)

- [ ] **Step 1: Write `public_html/admin/project_detail.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) { header('Location: /admin/projects.php'); exit; }
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>프로젝트 상세 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css?v=3">
<meta name="csrf-token" content="<?= e($token) ?>">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="/admin/">대시보드</a>
  <a href="/admin/users.php">사용자</a>
  <a href="/admin/projects.php">프로젝트</a>
  <a href="/admin/jobs.php">작업 큐</a>
  <span class="grow"></span><span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <a class="back-link" href="/admin/projects.php">← 프로젝트로</a>
  <header class="page-header">
    <h1 id="pname">프로젝트 상세</h1>
    <button id="refreshBtn">새로고침</button>
  </header>
  <div id="content"><p>불러오는 중…</p></div>
</main>
<script>
const pid = <?= json_encode($pid) ?>;
function escape(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

function gitCard(title, g) {
  if (!g || !g.ok) return `<div class="stat-card"><h3>${escape(title)}</h3><p class="bad">${escape((g&&g.error)||'읽기 실패')}</p></div>`;
  const rows = (g.log||[]).map(c => `<li><code>${escape(c.sha)}</code> ${escape(c.subject)} <span class="muted">${escape((c.date||'').slice(0,10))}</span></li>`).join('');
  return `<div class="stat-card">
    <h3>${escape(title)} <span class="muted">(${escape(g.branch||'')})</span></h3>
    <p><code>${escape(g.head)}</code> ${escape(g.subject||'')} <span class="muted">${escape(g.author||'')}</span></p>
    <ul class="commit-log">${rows}</ul></div>`;
}
function siteBadge(s) {
  if (!s) return '<span class="badge">?</span>';
  return s.up ? `<span class="badge ok">UP ${escape(String(s.code||''))}</span>` : '<span class="badge bad">응답 없음</span>';
}

async function load() {
  const content = document.getElementById('content');
  content.innerHTML = '<p>불러오는 중…</p>';
  let j;
  try { j = await (await fetch(`/api/system.php?action=project_status&op=get&id=${pid}`)).json(); }
  catch (e) { content.innerHTML = '<p class="bad">불러오기 실패</p>'; return; }
  if (!j.ok) { content.innerHTML = `<p class="bad">${escape(j.message||'불러오기 실패')}</p>`; return; }

  document.getElementById('pname').textContent = j.project.name + ' (' + j.project.slug + ')';

  let undep = '';
  if (j.undeployed && j.undeployed.ok) {
    if (j.undeployed.count === 0) undep = '<p class="ok">미배포 없음 (dev = 운영 기준)</p>';
    else undep = `<p class="warn">미배포 ${j.undeployed.count}건 (dev 가 main 보다 앞섬)</p><ul class="commit-log">` +
      (j.undeployed.commits||[]).map(c=>`<li><code>${escape(c.sha)}</code> ${escape(c.subject)}</li>`).join('') + '</ul>';
  } else {
    undep = `<p class="muted">미배포 비교 불가: ${escape((j.undeployed&&j.undeployed.error)||'')}</p>`;
  }

  let logHtml;
  if (j.log && j.log.ok) {
    logHtml = '<pre class="logbox">' + (j.log.lines||[]).map(escape).join('\n') + '</pre>';
  } else {
    logHtml = `<p class="muted">배포 로그 ${escape((j.log&&j.log.error)||'미설정')}</p>`;
  }

  content.innerHTML = `
    <section class="meta">
      <p><strong>repo:</strong> <code>${escape(j.project.github_repo)}</code></p>
      <p><strong>dev:</strong> ${siteBadge(j.sites.dev)} <code>${escape(j.project.dev_dir)}</code></p>
      <p><strong>운영:</strong> ${siteBadge(j.sites.prod)} <code>${escape(j.project.prod_dir)}</code></p>
    </section>
    <section class="git-cards">${gitCard('개발 (dev)', j.dev)}${gitCard('운영 (prod)', j.prod)}</section>
    <section><h2>미배포</h2>${undep}</section>
    <section><h2>배포 로그</h2>${logHtml}</section>
  `;
}
document.getElementById('refreshBtn').onclick = load;
load();
</script>
</body></html>
```

- [ ] **Step 2: Add [상세] link to project cards**

Modify `public_html/admin/projects.php` — in the `card-actions` template literal, add a 상세 link before 멤버 관리. Find:
```php
      <div class="card-actions">
        <a href="/admin/project_members.php?id=${p.id}">멤버 관리</a>
```
Replace with:
```php
      <div class="card-actions">
        <a href="/admin/project_detail.php?id=${p.id}">상세</a>
        <a href="/admin/project_members.php?id=${p.id}">멤버 관리</a>
```
Also bump its stylesheet link `?v=2` → `?v=3`:
Find `<link rel="stylesheet" href="/assets/style.css?v=2">` → replace `?v=2` with `?v=3`.

- [ ] **Step 3: Append detail styles to style.css**

Append to `public_html/assets/style.css`:
```css
/* ---- Project detail (Plan B observability) ---- */
.stat-card { padding: 16px; border: 1px solid var(--hairline); border-radius: var(--radius); background: #fff; }
.git-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 16px 0; }
.git-cards h3 { margin: 0 0 8px; font-size: 15px; }
.commit-log { list-style: none; padding: 0; margin: 8px 0 0; font-size: 13px; }
.commit-log li { padding: 3px 0; border-bottom: 1px solid var(--surface); }
.meta p { margin: 4px 0; font-size: 14px; }
.badge { display: inline-block; padding: 1px 7px; border-radius: 6px; font-size: 12px; font-weight: 600; background: var(--surface); color: var(--muted); }
.badge.ok { background: #dcfce7; color: var(--ok); }
.badge.bad { background: #fee2e2; color: var(--error); }
.ok { color: var(--ok); } .warn { color: #b45309; } .bad { color: var(--error); } .muted { color: var(--muted); }
.logbox { background: #1e1e1e; color: #e6e6e6; padding: 12px; border-radius: 8px; font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
@media (max-width: 720px) { .git-cards { grid-template-columns: 1fr; } }
```

- [ ] **Step 4: php -l + full suite + bump cache version everywhere consistent**

Run:
```bash
php -l public_html/admin/project_detail.php
./vendor/bin/phpunit 2>&1 | tail -3
grep -rho 'assets/style.css[^"]*' public_html/ | sort | uniq -c
```
Expected: no syntax errors; suite green; **모든** style.css 링크가 동일 버전이어야 함. projects.php 와 project_detail.php 는 `?v=3`, 나머지 페이지는 아직 `?v=2`.

> **중요(메모리 [[cache-buster-companion-update]]):** CSS 를 또 바꿨으므로 **모든 진입 페이지**의 `?v=` 를 통일해야 학부모 캐시 문제 없음. 이 plan 에선 CSS 를 append 하므로 전 페이지 `?v=3` 로 올린다.

- [ ] **Step 5: Bump ALL stylesheet links to ?v=3**

Run:
```bash
cd /var/www/html/_______site_SORITUNECOM_DEVELOPERS
grep -rl 'assets/style.css?v=2' public_html/ | while read f; do
  sed -i 's#assets/style.css?v=2#assets/style.css?v=3#g' "$f"
done
grep -rho 'assets/style.css[^"]*' public_html/ | sort | uniq -c
```
Expected: 모든 링크 `assets/style.css?v=3` (8~9개 동일).

- [ ] **Step 6: Live smoke**

```bash
curl -s -o /dev/null -w '%{http_code} %{redirect_url}\n' https://developers.soritune.com/admin/project_detail.php?id=1
curl -s -o /dev/null -w '%{http_code}\n' 'https://developers.soritune.com/assets/style.css?v=3'
```
Expected: detail 페이지 비로그인 → 302 → /login.php; css 200.

- [ ] **Step 7: Fix ownership + commit**

```bash
chown ec2-user:apache public_html/admin/project_detail.php public_html/admin/projects.php public_html/assets/style.css public_html/login.php public_html/me.php public_html/p/index.php public_html/admin/index.php public_html/admin/users.php public_html/admin/project_members.php public_html/admin/jobs.php
chmod 664 public_html/admin/project_detail.php public_html/admin/projects.php public_html/assets/style.css public_html/login.php public_html/me.php public_html/p/index.php public_html/admin/*.php
git add public_html/admin/project_detail.php public_html/admin/projects.php public_html/assets/style.css public_html/login.php public_html/me.php public_html/p/index.php public_html/admin/index.php public_html/admin/users.php public_html/admin/project_members.php public_html/admin/jobs.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat: admin/project_detail.php observability UI + card link + css v3"
```

---

## Task 6: junior 로 실제 검증 (수동) + 마무리

**Files:** (코드 변경 없음 — 검증/문서)

- [ ] **Step 1: junior 프로젝트가 등록돼 있는지 확인 (없으면 등록)**

binnie 로 로그인 → 프로젝트 → junior 가 없으면 등록(슬러그 `junior`, repo `pjuhe99/soritune-junior`, dev_dir `/var/www/html/_______site_SORITUNECOM_DEV_J`, prod_dir `/var/www/html/_______site_SORITUNECOM_J`, dev_subdomain `dev-j.soritune.com`, prod_subdomain `j.soritune.com`).

- [ ] **Step 2: 상세 화면 진입 → 실제 데이터 확인**

junior 카드 [상세] → dev/prod 카드에 실제 short SHA·subject·커밋 로그가 뜨고, 사이트 UP 배지, 미배포 비교가 표시되는지 눈으로 확인. (읽기만 — git 변화 없음)

- [ ] **Step 3: git 변화 없음 확인 (읽기 전용 증명)**

Run: `git -C /var/www/html/_______site_SORITUNECOM_DEV_J status -s 2>&1 | head` — 상세 화면 조회 전후로 working tree 변화 없어야 함 (포털은 읽기만).

- [ ] **Step 4: 최종 전체 suite + 클린 트리**

Run: `./vendor/bin/phpunit 2>&1 | tail -3 && git status --porcelain`
Expected: green; (index.php 외) clean.

- [ ] **Step 5: 메모리 업데이트**

`/root/.claude/projects/-root/memory/project_developers_soritune_design_wip.md` 에 Plan B 완료 기록 (HEAD, 신규 컴포넌트, junior 검증, deploy_log_path 컬럼).

---

## Self-Review

**Spec coverage:**
- §1 범위(git 상태/미배포/up·down/로그 tail/상세화면) → Task 2(GitInspector), 3(SiteCheck), 4(API), 5(UI) ✓
- §2 컴포넌트 책임 → 파일별 1:1 ✓
- §3 데이터모델(deploy_log_path) → Task 1 ✓
- §4 보안(git 쓰기0/인젝션0/게이트) → Task 2 Step 5(쓰기 부재 grep) + escapeshellarg + requireAdmin ✓
- §5 테스트(GitInspector/SiteCheck/ProjectStatusApi) → Task 2/3/4 ✓
- §7 비목표(능동배포/tasks/cron버그) → plan 에 미포함(의도적) ✓

**Placeholder scan:** 모든 step 에 실제 코드/명령. TBD/TODO 없음.

**Type consistency:** `GitInspector::inspect`→{ok,head,branch,subject,author,date,log[{sha,subject,date}]}, `countAhead`→{ok,count,commits[{sha,subject}]}, `SiteCheck::ping`→{up,code}. 핸들러·UI·테스트 전부 동일 키 사용. 라우터 키 `project_status` 일치.

**알려진 환경 주의:** 이 세션 bash/Read 출력이 자주 batch 지연. `git_owner=apache`, dubious-ownership 은 `-c safe.directory` 로 회피(Task 2 에 반영). composer dump-autoload 매 lib 추가 후 필수.
