# Plan C — project_init 마법사 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 관리자가 "새 프로젝트" 폼을 채우면 단일 `project_init` job 이 GitHub repo(+dev브랜치+ruleset 2개+collaborator) → Route53 A레코드×2 → site_manager dev/prod 사이트(vhost+certbot+빈DB) → 임시 clone 후 public_html 이동 → projects active 를 자동 수행한다.

**Architecture:** 포털 worker(apache, Plan A) 가 project_init job 을 픽업해 `scripts/job_project_init.sh` 오케스트레이터 실행. GitHub/Route53/clone 은 apache 권한(PAT/aws CLI/HTTPS). root 작업(vhost/certbot/DB/소유권/SELinux)은 **기존 site_manager 파일큐 + 기존 root cron** 에 위임(새 root 권한 0). 각 외부 호출은 주입 가능한 runner 로 추상화해 mock 단위테스트.

**Tech Stack:** PHP 8 / MariaDB 10.5 / Apache. composer PSR-4 (`Soritune\Developers\`), phpunit 10, `gh`/`aws`/`git`/`certbot`(via site_manager) CLI. 기존 패턴: jsonSuccess/jsonError(test 모드 exit 안 함 → switch case 마다 return;), JobQueue, e()/escape().

**Spec:** `docs/superpowers/specs/2026-05-29-plan-c-project-init-design.md` (HEAD ce920a3 기준)

## 핵심 실측 사실 (구현 전 반드시 인지)
- site_manager.sh: `site_manager.sh <action> <subdomain>` — subdomain 은 **`.soritune.com` 뗀 bare 라벨**(예 `dev-j`). dir/DB 이름은 `DERIVED=$(echo "$SUBDOMAIN" | tr 'a-z-' 'A-Z_')` 로 자동 도출 → SITE_DIR `/var/www/html/_______site_SORITUNECOM_<DERIVED>`, DB `SORITUNECOM_<DERIVED>`.
- site_manager create_folders: `apache:apache` 소유, `.db_credentials`(640) + logs(httpd_log_t) + `public_html/index.php`(ROBOTION 기본) 생성. → 포털은 추가 chown/restorecon **안 함**.
- site_manager actions (순서): `check_conflict → create_folders → create_database → create_vhost_http → issue_ssl → create_vhost_ssl`. 각 액션 결과는 `{"success":true|false,...}` JSON.
- site_manager 파일큐: pending `=/var/www/html/_______site_SORITUNECOM_APP/jobs/pending/<id>.json {action, subdomain}`, done `=.../jobs/done/<id>.json`. 기존 root cron(`/root/site_manager_cron.sh`, 매분 5초마다)이 처리.
- 서버 IP: `3.37.213.224`. DNS: Route53 `soritune.com` zone.
- **projects.dev_dir/prod_dir = `<SITE_DIR>/public_html`** (코드+`.git` 위치, Plan B GitInspector 가 읽는 곳). projects.dev_db_name/prod_db_name = `SORITUNECOM_<DERIVED>`.
- PAT: `.env` 의 `GITHUB_TOKEN` / `GITHUB_ACCOUNT` / `GITHUB_ACCOUNT_TYPE=user`.

---

## File Structure
```
lib/CliRunner.php                          (신규) shell 명령 실행 추상화 (주입 가능 → mock)
lib/GithubAdmin.php                        (신규) PAT 로 repo/dev브랜치/ruleset(2)/collaborator
lib/Route53.php                            (신규) aws route53 UPSERT A레코드
lib/SiteManagerClient.php                  (신규) site_manager 파일큐 enqueue+폴링
lib/ProjectNaming.php                      (신규) subdomain→bare/DERIVED/dir/db 도출 (순수함수)
scripts/job_project_init.sh                (신규) 오케스트레이터
scripts/manual_rollback_project.sh         (신규) 부분실패 수동 정리
public_html/api/system/projects.php        (수정) op=init 추가
public_html/admin/projects.php             (수정) 마법사 폼
public_html/admin/settings.php             (신규) GITHUB_TOKEN 등 마스킹 표시
public_html/api/system.php                 (수정) handlerMap 에 settings (필요시)
public_html/assets/style.css               (수정) 폼/settings 스타일 + ?v=4
tests/unit/ProjectNamingTest.php           (신규)
tests/unit/GithubAdminTest.php             (신규)
tests/unit/Route53Test.php                 (신규)
tests/unit/SiteManagerClientTest.php       (신규)
tests/integration/ProjectInitApiTest.php   (신규)
```

---

## Task 1: ProjectNaming — subdomain→dir/db 도출 (순수함수, 가장 안전)

**Files:** Create `lib/ProjectNaming.php`, Test `tests/unit/ProjectNamingTest.php`

- [ ] **Step 1: Write failing test** `tests/unit/ProjectNamingTest.php`:
```php
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
        // already bare → returned as-is
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
```
- [ ] **Step 2: Run — expect fail** `./vendor/bin/phpunit --filter ProjectNamingTest 2>&1 | tail -6` (class not found)
- [ ] **Step 3: Write `lib/ProjectNaming.php`:**
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

/** Derives site dir / DB name from a subdomain exactly like site_manager.sh
 *  (DERIVED = tr 'a-z-' 'A-Z_'). Pure, no side effects. */
final class ProjectNaming
{
    public static function fromSubdomain(string $subdomain): array
    {
        $bare = preg_replace('/\.soritune\.com$/', '', trim($subdomain));
        // site_manager accepts the bare label; validate it as a single dns label set
        if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $bare)) {
            throw new \InvalidArgumentException("invalid subdomain: $subdomain");
        }
        $derived = strtr($bare, 'abcdefghijklmnopqrstuvwxyz-', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ_');
        $siteDir = "/var/www/html/_______site_SORITUNECOM_{$derived}";
        return [
            'bare'     => $bare,
            'derived'  => $derived,
            'site_dir' => $siteDir,
            'code_dir' => "{$siteDir}/public_html",
            'db_name'  => "SORITUNECOM_{$derived}",
        ];
    }
}
```
- [ ] **Step 4: dump-autoload + run — expect pass** `composer dump-autoload && ./vendor/bin/phpunit --filter ProjectNamingTest 2>&1 | tail -6` (4 pass)
- [ ] **Step 5: Full suite + ownership + commit**
```bash
./vendor/bin/phpunit 2>&1 | tail -3
chown ec2-user:apache lib/ProjectNaming.php tests/unit/ProjectNamingTest.php && chmod 664 lib/ProjectNaming.php tests/unit/ProjectNamingTest.php
git add lib/ProjectNaming.php tests/unit/ProjectNamingTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): ProjectNaming — derive dir/db from subdomain like site_manager"
```

---

## Task 2: CliRunner — 주입 가능한 명령 실행 추상화

**Files:** Create `lib/CliRunner.php`, Test `tests/unit/` (covered via GithubAdmin/Route53 mocks; a tiny direct test here)

- [ ] **Step 1: Write `lib/CliRunner.php`:**
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

/** Runs a shell command, returns ['code'=>int,'out'=>string,'err'=>string].
 *  Injectable: tests pass a fake callable to constructors that take CliRunner. */
final class CliRunner
{
    /** @var callable|null */
    private $fake;

    /** @param callable|null $fake fn(string $cmd): array{code:int,out:string,err:string} */
    public function __construct(?callable $fake = null) { $this->fake = $fake; }

    public function run(string $cmd): array
    {
        if ($this->fake !== null) { return ($this->fake)($cmd); }
        $descriptors = [1 => ['pipe','w'], 2 => ['pipe','w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!\is_resource($proc)) { return ['code'=>127,'out'=>'','err'=>'proc_open failed']; }
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $code = proc_close($proc);
        return ['code'=>$code, 'out'=>(string)$out, 'err'=>(string)$err];
    }
}
```
- [ ] **Step 2: Write `tests/unit/CliRunnerTest.php`:**
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\CliRunner;

final class CliRunnerTest extends TestCase
{
    public function testRealEcho(): void
    {
        $r = (new CliRunner())->run('echo hi');
        $this->assertSame(0, $r['code']);
        $this->assertSame('hi', trim($r['out']));
    }
    public function testNonZero(): void
    {
        $r = (new CliRunner())->run('exit 3');
        $this->assertSame(3, $r['code']);
    }
    public function testFakeInjection(): void
    {
        $runner = new CliRunner(fn($cmd) => ['code'=>0,'out'=>"FAKE:$cmd",'err'=>'']);
        $this->assertSame('FAKE:whatever', $runner->run('whatever')['out']);
    }
}
```
- [ ] **Step 3: dump-autoload + run + full suite + commit**
```bash
composer dump-autoload && ./vendor/bin/phpunit --filter CliRunnerTest 2>&1 | tail -5
./vendor/bin/phpunit 2>&1 | tail -3
chown ec2-user:apache lib/CliRunner.php tests/unit/CliRunnerTest.php && chmod 664 lib/CliRunner.php tests/unit/CliRunnerTest.php
git add lib/CliRunner.php tests/unit/CliRunnerTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): CliRunner — injectable shell exec wrapper"
```

---

## Task 3: GithubAdmin — repo/dev브랜치/ruleset(2)/collaborator (PAT)

**Files:** Create `lib/GithubAdmin.php`, Test `tests/unit/GithubAdminTest.php`

- [ ] **Step 1: Write failing test** `tests/unit/GithubAdminTest.php`:
```php
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
            // POST /user/repos succeeds
            return ['code'=>0,'out'=>json_encode(['full_name'=>'acct/camp','html_url'=>'https://github.com/acct/camp']),'err'=>''];
        });
        $r = $ga->createRepo('camp', 'desc');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['created']);
        $this->assertSame('acct/camp', $r['full_name']);
    }

    public function testCreateRepoExistsRecovers(): void
    {
        $calls = 0;
        $ga = $this->ga(function ($cmd) use (&$calls) {
            $calls++;
            if (str_contains($cmd, 'POST') || str_contains($cmd, '/user/repos')) {
                // name already exists → gh returns non-zero with 422
                if (str_contains($cmd, 'repos/acct/camp') && str_contains($cmd, 'GET')) {
                    return ['code'=>0,'out'=>json_encode(['full_name'=>'acct/camp','html_url'=>'https://github.com/acct/camp','owner'=>['login'=>'acct']]),'err'=>''];
                }
                return ['code'=>1,'out'=>'','err'=>'HTTP 422 name already exists'];
            }
            // GET recovery
            return ['code'=>0,'out'=>json_encode(['full_name'=>'acct/camp','html_url'=>'https://github.com/acct/camp','owner'=>['login'=>'acct']]),'err'=>''];
        });
        $r = $ga->createRepo('camp', 'desc');
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertFalse($r['created']);   // recovered, not newly created
        $this->assertSame('acct/camp', $r['full_name']);
    }

    public function testCreateRepoExistsOtherOwnerFails(): void
    {
        $ga = $this->ga(function ($cmd) {
            if (str_contains($cmd, 'GET') && str_contains($cmd, 'repos/acct/camp')) {
                // exists but owned by someone else
                return ['code'=>0,'out'=>json_encode(['full_name'=>'other/camp','owner'=>['login'=>'other']]),'err'=>''];
            }
            return ['code'=>1,'out'=>'','err'=>'HTTP 422 name already exists'];
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
        $this->assertCount(2, $r['ruleset_ids']);   // main + dev, NO ref-name restriction
    }

    public function testAddRulesetsFailIsFatal(): void
    {
        $ga = $this->ga(fn($cmd) => ['code'=>1,'out'=>'','err'=>'forbidden']);
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
}
```
- [ ] **Step 2: Run — expect fail**
- [ ] **Step 3: Write `lib/GithubAdmin.php`** — token never in argv (use `GH_TOKEN` env via `CliRunner` cmd prefix; token is escapeshellarg'd in an env assignment). API via `gh api`:
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class GithubAdmin
{
    public function __construct(
        private string $token,
        private string $account,
        private string $accountType,   // 'user' | 'org'
        private CliRunner $runner
    ) {}

    /** gh api wrapper. $args already shell-safe pieces. Returns CliRunner result. */
    private function gh(string $args): array
    {
        $env = 'GH_TOKEN=' . escapeshellarg($this->token);
        return $this->runner->run("$env gh api $args 2>&1");
    }

    public function createRepo(string $slug, string $description): array
    {
        $slug = trim($slug);
        $endpoint = $this->accountType === 'org'
            ? '/orgs/' . escapeshellarg($this->account) . '/repos'
            : '/user/repos';
        $r = $this->gh("-X POST $endpoint "
            . '-f name=' . escapeshellarg($slug) . ' '
            . '-f description=' . escapeshellarg($description) . ' '
            . '-F private=true -F auto_init=true');
        if ($r['code'] === 0) {
            $j = json_decode($r['out'], true) ?: [];
            return ['ok'=>true,'created'=>true,'full_name'=>$j['full_name'] ?? "{$this->account}/$slug",
                    'repo_url'=>$j['html_url'] ?? ''];
        }
        // exists? recover via GET, but only if it's OUR account's repo
        $g = $this->gh('-X GET /repos/' . escapeshellarg($this->account) . '/' . escapeshellarg($slug));
        if ($g['code'] === 0) {
            $j = json_decode($g['out'], true) ?: [];
            $owner = $j['owner']['login'] ?? '';
            if ($owner === $this->account) {
                return ['ok'=>true,'created'=>false,'full_name'=>$j['full_name'],'repo_url'=>$j['html_url'] ?? ''];
            }
            return ['ok'=>false,'error'=>'repo name taken by other owner'];
        }
        return ['ok'=>false,'error'=>'createRepo failed: ' . trim($r['out'])];
    }

    public function createDevBranch(string $fullName): array
    {
        $sha = $this->gh("-X GET /repos/$fullName/git/ref/heads/main --jq .object.sha");
        if ($sha['code'] !== 0) return ['ok'=>false,'error'=>'main ref not found'];
        $main = trim($sha['out']);
        $r = $this->gh("-X POST /repos/$fullName/git/refs -f ref=refs/heads/dev -f sha=" . escapeshellarg($main));
        if ($r['code'] === 0) return ['ok'=>true,'existed'=>false];
        // 422 reference exists → ok
        if (str_contains($r['out'], 'already exists') || str_contains($r['out'], '422')) return ['ok'=>true,'existed'=>true];
        return ['ok'=>false,'error'=>'createDevBranch failed: ' . trim($r['out'])];
    }

    /** 2 rulesets: main protected + dev protected. NO ref-name restriction (P0-B). */
    public function addRulesets(string $fullName): array
    {
        $ids = [];
        foreach (['main','dev'] as $branch) {
            $rules = $branch === 'main'
                ? '[{"type":"deletion"},{"type":"non_fast_forward"},{"type":"required_linear_history"}]'
                : '[{"type":"deletion"},{"type":"non_fast_forward"}]';
            // ruleset payload via --input - (stdin). Use a here-doc through CliRunner.
            $payload = json_encode([
                'name' => "protect-$branch",
                'target' => 'branch',
                'enforcement' => 'active',
                'conditions' => ['ref_name' => ['include' => ["refs/heads/$branch"], 'exclude' => []]],
                'rules' => json_decode($rules, true),
            ], JSON_UNESCAPED_SLASHES);
            $env = 'GH_TOKEN=' . escapeshellarg($this->token);
            $cmd = "printf %s " . escapeshellarg($payload)
                 . " | $env gh api -X POST /repos/$fullName/rulesets --input - 2>&1";
            $r = $this->runner->run($cmd);
            if ($r['code'] !== 0) {
                return ['ok'=>false,'error'=>"ruleset $branch failed: " . trim($r['out']), 'ruleset_ids'=>$ids];
            }
            $j = json_decode($r['out'], true) ?: [];
            $ids[] = $j['id'] ?? null;
        }
        return ['ok'=>true,'ruleset_ids'=>$ids];
    }

    public function addCollaborators(string $fullName, array $usernames, string $role = 'push'): array
    {
        $added = []; $skipped = [];
        foreach ($usernames as $u) {
            if (trim((string)$u) === '') { $skipped[] = $u; continue; }
            $r = $this->gh("-X PUT /repos/$fullName/collaborators/" . escapeshellarg($u)
                . ' -f permission=' . escapeshellarg($role));
            if ($r['code'] === 0) $added[] = $u; else $skipped[] = $u;
        }
        return ['ok'=>true,'added'=>$added,'skipped'=>$skipped];
    }
}
```
- [ ] **Step 4: dump-autoload + run — expect pass (6 tests).** Adjust test command-matching if needed (the fake inspects `$cmd` substrings — keep assertions, fix fake if brittle). Do NOT weaken assertions.
- [ ] **Step 5: Full suite + ownership + commit**
```bash
./vendor/bin/phpunit 2>&1 | tail -3
chown ec2-user:apache lib/GithubAdmin.php tests/unit/GithubAdminTest.php && chmod 664 lib/GithubAdmin.php tests/unit/GithubAdminTest.php
git add lib/GithubAdmin.php tests/unit/GithubAdminTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): GithubAdmin — repo(idempotent)/dev-branch/rulesets(2)/collaborators via PAT"
```

---

## Task 4: Route53 — A레코드 UPSERT

**Files:** Create `lib/Route53.php`, Test `tests/unit/Route53Test.php`

- [ ] **Step 1: Write failing test** `tests/unit/Route53Test.php`:
```php
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
```
- [ ] **Step 2: Run — expect fail**
- [ ] **Step 3: Write `lib/Route53.php`:**
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class Route53
{
    public function __construct(private string $ip, private CliRunner $runner) {}

    public function upsertA(string $fqdn, int $ttl = 300): array
    {
        $fqdn = rtrim(trim($fqdn), '.');
        $zr = $this->runner->run('aws route53 list-hosted-zones --output json 2>&1');
        if ($zr['code'] !== 0) return ['ok'=>false,'error'=>'list-hosted-zones failed'];
        $zones = json_decode($zr['out'], true)['HostedZones'] ?? [];
        $zoneId = null;
        foreach ($zones as $z) {
            if (rtrim($z['Name'], '.') === 'soritune.com') { $zoneId = $z['Id']; break; }
        }
        if ($zoneId === null) return ['ok'=>false,'error'=>'soritune.com zone not found'];

        $batch = json_encode([
            'Changes' => [[
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'Name' => $fqdn . '.', 'Type' => 'A', 'TTL' => $ttl,
                    'ResourceRecords' => [['Value' => $this->ip]],
                ],
            ]],
        ], JSON_UNESCAPED_SLASHES);

        $cmd = 'aws route53 change-resource-record-sets '
             . '--hosted-zone-id ' . escapeshellarg($zoneId) . ' '
             . '--change-batch ' . escapeshellarg($batch) . ' --output json 2>&1';
        $r = $this->runner->run($cmd);
        if ($r['code'] !== 0) return ['ok'=>false,'error'=>'change failed: ' . trim($r['out'])];
        return ['ok'=>true,'fqdn'=>$fqdn];
    }
}
```
- [ ] **Step 4: dump-autoload + run — expect pass (2)**
- [ ] **Step 5: Full suite + ownership + commit**
```bash
./vendor/bin/phpunit 2>&1 | tail -3
chown ec2-user:apache lib/Route53.php tests/unit/Route53Test.php && chmod 664 lib/Route53.php tests/unit/Route53Test.php
git add lib/Route53.php tests/unit/Route53Test.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): Route53 — UPSERT A record (idempotent)"
```

---

## Task 5: SiteManagerClient — site_manager 파일큐 enqueue+폴링

**Files:** Create `lib/SiteManagerClient.php`, Test `tests/unit/SiteManagerClientTest.php`

- [ ] **Step 1: Write failing test** `tests/unit/SiteManagerClientTest.php`:
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\SiteManagerClient;

final class SiteManagerClientTest extends TestCase
{
    private string $pending; private string $done;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/smc_' . bin2hex(random_bytes(4));
        $this->pending = "$base/pending"; $this->done = "$base/done";
        mkdir($this->pending, 0777, true); mkdir($this->done, 0777, true);
    }
    protected function tearDown(): void
    {
        shell_exec('rm -rf ' . escapeshellarg(dirname($this->pending)));
    }

    /** Simulate the root cron: read pending, write a done result, remove pending. */
    private function fakeCron(string $result): void
    {
        foreach (glob($this->pending . '/*.json') as $f) {
            $id = basename($f, '.json');
            file_put_contents($this->done . "/$id.json", $result);
            unlink($f);
        }
    }

    public function testRunActionSuccess(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 2 /*timeout s*/, 0 /*poll us*/);
        // pre-stage the done result the moment the action is enqueued via a callback
        $c->setOnEnqueued(fn() => $this->fakeCron('{"success":true,"db_pass":"x"}'));
        $r = $c->runAction('create_folders', 'camp-dev');
        $this->assertTrue($r['ok'], json_encode($r));
    }

    public function testRunActionFailure(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 2, 0);
        $c->setOnEnqueued(fn() => $this->fakeCron('{"success":false,"error":"boom"}'));
        $r = $c->runAction('issue_ssl', 'camp-dev');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('boom', $r['error']);
    }

    public function testRunActionTimeout(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 1, 0);
        // no fakeCron → never completes
        $r = $c->runAction('check_conflict', 'camp-dev');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('timeout', $r['error']);
    }

    public function testProvisionSkipsOnConflictExists(): void
    {
        $c = new SiteManagerClient($this->pending, $this->done, 2, 0);
        $c->setOnEnqueued(function () {
            // check_conflict reports already exists → provision should treat site as done
            $this->fakeCron('{"success":false,"error":"already exists","exists":true}');
        });
        $r = $c->provision('camp-dev');
        $this->assertTrue($r['ok']);
        $this->assertTrue($r['skipped']);
    }
}
```
- [ ] **Step 2: Run — expect fail**
- [ ] **Step 3: Write `lib/SiteManagerClient.php`:**
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class SiteManagerClient
{
    public const SEQUENCE = ['check_conflict','create_folders','create_database','create_vhost_http','issue_ssl','create_vhost_ssl'];
    /** @var callable|null */ private $onEnqueued = null;

    public function __construct(
        private string $pendingDir,
        private string $doneDir,
        private int $timeoutSec = 300,
        private int $pollUs = 2_000_000
    ) {}

    public function setOnEnqueued(?callable $cb): void { $this->onEnqueued = $cb; }

    /** Enqueue one action, poll done until result or timeout. */
    public function runAction(string $action, string $bareSubdomain): array
    {
        $id = 'dev_' . bin2hex(random_bytes(6));
        $job = ['action' => $action, 'subdomain' => $bareSubdomain];
        file_put_contents($this->pendingDir . "/$id.json", json_encode($job, JSON_UNESCAPED_SLASHES));
        if ($this->onEnqueued !== null) { ($this->onEnqueued)(); }

        $deadline = time() + $this->timeoutSec;
        $donePath = $this->doneDir . "/$id.json";
        while (time() <= $deadline) {
            if (is_file($donePath)) {
                $res = json_decode((string)file_get_contents($donePath), true) ?: [];
                if (($res['success'] ?? false) === true) return ['ok'=>true,'result'=>$res];
                return ['ok'=>false,'error'=>$res['error'] ?? 'unknown', 'result'=>$res];
            }
            if ($this->pollUs > 0) usleep($this->pollUs);
            else break; // test mode single-shot after onEnqueued staged result
        }
        // one final check (covers pollUs=0 single-shot)
        if (is_file($donePath)) {
            $res = json_decode((string)file_get_contents($donePath), true) ?: [];
            if (($res['success'] ?? false) === true) return ['ok'=>true,'result'=>$res];
            return ['ok'=>false,'error'=>$res['error'] ?? 'unknown','result'=>$res,'exists'=>$res['exists'] ?? false];
        }
        return ['ok'=>false,'error'=>'timeout waiting for site_manager'];
    }

    /** Full provision sequence for one site. If check_conflict says it already exists,
     *  treat the whole site as already provisioned (idempotent rerun) and skip. */
    public function provision(string $bareSubdomain): array
    {
        $first = $this->runAction('check_conflict', $bareSubdomain);
        if (!$first['ok']) {
            if (($first['exists'] ?? false) === true || str_contains((string)($first['error'] ?? ''), 'already exists')) {
                return ['ok'=>true,'skipped'=>true];
            }
            return ['ok'=>false,'step'=>'check_conflict','error'=>$first['error']];
        }
        foreach (['create_folders','create_database','create_vhost_http','issue_ssl','create_vhost_ssl'] as $action) {
            $r = $this->runAction($action, $bareSubdomain);
            if (!$r['ok']) return ['ok'=>false,'step'=>$action,'error'=>$r['error']];
        }
        return ['ok'=>true,'skipped'=>false];
    }
}
```
- [ ] **Step 4: dump-autoload + run — expect pass (4).** Note: the test's `pollUs=0` single-shot relies on onEnqueued staging the result before the final check. Verify the control flow; if a test hangs, the timeout path covers it.
- [ ] **Step 5: Full suite + ownership + commit**
```bash
./vendor/bin/phpunit 2>&1 | tail -3
chown ec2-user:apache lib/SiteManagerClient.php tests/unit/SiteManagerClientTest.php && chmod 664 lib/SiteManagerClient.php tests/unit/SiteManagerClientTest.php
git add lib/SiteManagerClient.php tests/unit/SiteManagerClientTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): SiteManagerClient — file-queue enqueue+poll, idempotent provision"
```

---

## Task 6: .env 시크릿 + admin/settings.php (마스킹 표시)

**Files:** Create `public_html/admin/settings.php`, Modify `public_html/admin/index.php` (nav 링크), `public_html/assets/style.css` (?v=4 bump 포함)

- [ ] **Step 1: Add secrets to `.env`** (manual — user provides real PAT later; placeholder ok for now):
```bash
cd /var/www/html/_______site_SORITUNECOM_DEVELOPERS
grep -q '^GITHUB_ACCOUNT=' .env || cat >> .env <<'ENV'
GITHUB_ACCOUNT=
GITHUB_ACCOUNT_TYPE=user
GITHUB_TOKEN=
ENV
# .env stays apache:apache 640 (already)
```
- [ ] **Step 2: Write `public_html/admin/settings.php`** (read-only masking, requireAdmin):
```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$mask = function (string $v): string {
    $v = trim($v);
    if ($v === '') return '<em>(미설정)</em>';
    return e(strlen($v) > 8 ? '****' . substr($v, -4) : '****');
};
$env = loadEnv();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>설정 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css?v=4">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="/admin/">대시보드</a>
  <a href="/admin/users.php">사용자</a>
  <a href="/admin/projects.php">프로젝트</a>
  <a href="/admin/jobs.php">작업 큐</a>
  <a href="/admin/settings.php">설정</a>
  <span class="grow"></span><span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <h1>설정</h1>
  <p class="hint">시크릿은 서버 <code>.env</code> 파일(apache:apache 640)에만 저장됩니다. 여기서는 마스킹만 표시.</p>
  <table class="data-table">
    <tr><th>GITHUB_ACCOUNT</th><td><?= e($env['GITHUB_ACCOUNT'] ?? '') ?: '<em>(미설정)</em>' ?></td></tr>
    <tr><th>GITHUB_ACCOUNT_TYPE</th><td><?= e($env['GITHUB_ACCOUNT_TYPE'] ?? 'user') ?></td></tr>
    <tr><th>GITHUB_TOKEN</th><td><?= $mask($env['GITHUB_TOKEN'] ?? '') ?></td></tr>
  </table>
</main>
</body></html>
```
(NOTE: `loadEnv()` exists in config.php. `e()` strict — pass `?? ''`.)
- [ ] **Step 3: Add settings nav link to other admin pages** — bump ALL `?v=3` → `?v=4` and add `<a href="/admin/settings.php">설정</a>` to each topnav (admin/index, users, projects, jobs, project_detail, project_members). Use sed for ?v bump:
```bash
cd /var/www/html/_______site_SORITUNECOM_DEVELOPERS
grep -rl 'assets/style.css?v=3' public_html/ | while read f; do sed -i 's#style.css?v=3#style.css?v=4#g' "$f"; done
grep -rho 'assets/style.css[^"]*' public_html/ | sort | uniq -c   # expect uniform v=4
```
(Adding the 설정 nav link to every page is optional polish; at minimum admin/index links it.)
- [ ] **Step 4: php -l + smoke + commit**
```bash
php -l public_html/admin/settings.php
curl -s -o /dev/null -w '%{http_code}\n' https://developers.soritune.com/admin/settings.php   # 302 (unauth)
./vendor/bin/phpunit 2>&1 | tail -3
chown ec2-user:apache public_html/admin/settings.php public_html/assets/style.css public_html/admin/*.php && chmod 664 public_html/admin/settings.php public_html/assets/style.css public_html/admin/*.php
git add -A public_html/
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): admin/settings.php secret masking + css v4 + nav link"
```

---

## Task 7: op=init API + 마법사 폼

**Files:** Modify `public_html/api/system/projects.php` (op=init), `public_html/admin/projects.php` (wizard form), Test `tests/integration/ProjectInitApiTest.php`

- [ ] **Step 1: Write failing integration test** `tests/integration/ProjectInitApiTest.php` — verifies the GATE + enqueue only (does NOT run the job):
```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class ProjectInitApiTest extends TestCase
{
    private static int $adminId;
    private static string $csrf = 'test-init-csrf-1234567890';
    private static array $created = [];

    public static function setUpBeforeClass(): void
    {
        $db = getDB();
        $db->prepare("INSERT INTO users (username,password_hash,display_name,role,must_change_password,active) VALUES ('initadmin','x','Init','admin',0,1)")->execute();
        self::$adminId = (int)$db->lastInsertId();
    }
    public static function tearDownAfterClass(): void
    {
        $db = getDB();
        foreach (self::$created as $pid) {
            $db->prepare("DELETE FROM jobs WHERE project_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM projects WHERE id=?")->execute([$pid]);
        }
        $db->prepare("DELETE FROM jobs WHERE user_id=?")->execute([self::$adminId]);
        $db->prepare("DELETE FROM users WHERE id=?")->execute([self::$adminId]);
    }

    private function call(array $params): array
    {
        startSessionOnce();
        $_SESSION['user'] = ['id'=>self::$adminId,'username'=>'initadmin','display_name'=>'Init','role'=>'admin'];
        $_SESSION['csrf'] = self::$csrf;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_GET = ['action'=>'projects'];
        $_POST = array_merge(['op'=>'init','_csrf'=>self::$csrf], $params);
        ob_start();
        try { require __DIR__ . '/../../public_html/api/system/projects.php'; } catch (\Throwable $e) {}
        $raw = ob_get_clean(); $_GET=[]; $_POST=[];
        $d = json_decode($this->firstJson($raw), true);
        if (is_array($d) && !empty($d['project']['id'])) self::$created[] = (int)$d['project']['id'];
        return is_array($d) ? $d : [];
    }
    private function firstJson(string $raw): string
    {
        $depth=0;$s=false;$in=false;$esc=false;
        for($i=0,$n=strlen($raw);$i<$n;$i++){$c=$raw[$i];
            if($s===false&&$c==='{')$s=$i; if($s===false)continue;
            if($esc){$esc=false;continue;} if($c==='\\'&&$in){$esc=true;continue;}
            if($c==='"'){$in=!$in;continue;}
            if(!$in){if($c==='{')$depth++;elseif($c==='}'){$depth--;if($depth===0)return substr($raw,$s,$i-$s+1);}}}
        return $raw;
    }

    public function testInitValidatesSlug(): void
    {
        $r = $this->call(['slug'=>'Bad Slug','name'=>'X','dev_subdomain'=>'x-dev.soritune.com','prod_subdomain'=>'x.soritune.com']);
        $this->assertFalse($r['ok'] ?? true);
    }

    public function testInitCreatesProvisioningRowAndJob(): void
    {
        $slug = 'cinit' . bin2hex(random_bytes(3));
        $r = $this->call(['slug'=>$slug,'name'=>'C Init','description'=>'d',
            'dev_subdomain'=>"dev-$slug.soritune.com",'prod_subdomain'=>"$slug.soritune.com",'member_ids'=>'']);
        $this->assertTrue($r['ok'] ?? false, json_encode($r));
        $pid = (int)$r['project']['id'];
        $db = getDB();
        $row = $db->query("SELECT status FROM projects WHERE id=$pid")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('provisioning', $row['status']);
        $job = $db->query("SELECT type,status FROM jobs WHERE project_id=$pid")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('project_init', $job['type']);
        $this->assertSame('pending', $job['status']);
    }
}
```
- [ ] **Step 2: Run — expect fail**
- [ ] **Step 3: Add `op=init` case to `public_html/api/system/projects.php`** (before `default:`), using ProjectNaming + Validation + JobQueue. Each case ends with `return;`:
```php
    case 'init': {
        $slug   = trim((string)($_POST['slug'] ?? ''));
        $name   = trim((string)($_POST['name'] ?? ''));
        $desc   = trim((string)($_POST['description'] ?? ''));
        $devSub = trim((string)($_POST['dev_subdomain'] ?? ''));
        $prodSub= trim((string)($_POST['prod_subdomain'] ?? ''));
        $memberIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['member_ids'] ?? '')))));

        if (!Validation::isValidSlug($slug)) { jsonError('invalid slug'); return; }
        if ($name === '') { jsonError('name required'); return; }
        if (!Validation::isValidSubdomain($devSub)) { jsonError('invalid dev_subdomain'); return; }
        if (!Validation::isValidSubdomain($prodSub)) { jsonError('invalid prod_subdomain'); return; }

        try {
            $dev  = \Soritune\Developers\ProjectNaming::fromSubdomain($devSub);
            $prod = \Soritune\Developers\ProjectNaming::fromSubdomain($prodSub);
        } catch (\InvalidArgumentException $e) { jsonError('subdomain parse failed'); return; }

        $db = getDB();
        $db->beginTransaction();
        try {
            // github_repo is NOT NULL. Store the INTENDED full_name now (<account>/<slug>
            // if GITHUB_ACCOUNT is set, else just <slug> as a non-empty placeholder);
            // the project_init job overwrites it with the real full_name after createRepo.
            $acct = trim((string)(loadEnv()['GITHUB_ACCOUNT'] ?? ''));
            $githubRepo = $acct !== '' ? "$acct/$slug" : $slug;
            $st = $db->prepare(
                "INSERT INTO projects (slug,name,description,github_repo,dev_subdomain,prod_subdomain,dev_dir,prod_dir,dev_db_name,prod_db_name,status,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?, 'provisioning', ?)"
            );
            $st->execute([$slug,$name,($desc?:null),$githubRepo,
                $devSub,$prodSub,$dev['code_dir'],$prod['code_dir'],$dev['db_name'],$prod['db_name'],currentUser()['id']]);
        } catch (\PDOException $e) {
            $db->rollBack();
            if (($e->errorInfo[1] ?? null) === 1062) { jsonError('slug already exists', 409); return; }
            throw $e;
        }
        $pid = (int)$db->lastInsertId();
        $jobId = \Soritune\Developers\JobQueue::enqueue('project_init', [
            'project_id'=>$pid,'slug'=>$slug,'name'=>$name,'description'=>$desc,
            'dev_subdomain'=>$devSub,'prod_subdomain'=>$prodSub,
            'dev_bare'=>$dev['bare'],'prod_bare'=>$prod['bare'],
            'member_ids'=>$memberIds,
        ], (int)currentUser()['id'], $pid);
        $db->prepare("UPDATE projects SET init_job_id=? WHERE id=?")->execute([$jobId,$pid]);
        $db->commit();
        Audit::writeFromRequest(currentUser()['id'], 'project.init', 'project', $pid, ['slug'=>$slug]);
        jsonSuccess(['project'=>['id'=>$pid,'slug'=>$slug],'job_id'=>$jobId], 'provisioning');
        return;
    }
```
**NOTE (implementer):** `Validation`/`Audit`/`JobQueue` are referenced — they are already autoloaded (used by the existing projects.php handler). `ProjectNaming` 은 위에서 fully-qualified `\Soritune\Developers\ProjectNaming` 로 호출했으니 use 문 불필요. github_repo 는 위 코드대로 intended full_name 을 먼저 저장하고 job 이 createRepo 후 실제 full_name 으로 덮어쓴다(run_project_init.php Step 8 의 `UPDATE projects SET github_repo=?`).
- [ ] **Step 4: Add wizard form to `public_html/admin/projects.php`** — extend the existing register dialog (or add a new one) with fields: slug, name, description, dev_subdomain (default `dev-<slug>.soritune.com`), prod_subdomain (default `<slug>.soritune.com`), members (multi-select of active users). On submit POST `op=init`. Reuse existing escape()/preventDefault-first/CSRF pattern. After success, show "프로비저닝 시작 — 작업 큐에서 진행 확인" and link to jobs page.
- [ ] **Step 5: Run test + full suite + smoke**
```bash
./vendor/bin/phpunit --filter ProjectInitApiTest 2>&1 | tail -8
./vendor/bin/phpunit 2>&1 | tail -3
curl -s -o /dev/null -w '%{http_code}\n' "https://developers.soritune.com/api/system.php?action=projects&op=init"  # 401 unauth
# cleanup any leftover cinit* projects/jobs:
set -a; . ./.db_credentials; set +a
mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "DELETE j FROM jobs j JOIN projects p ON j.project_id=p.id WHERE p.slug LIKE 'cinit%'; DELETE FROM projects WHERE slug LIKE 'cinit%';"
```
- [ ] **Step 6: ownership + commit**
```bash
chown ec2-user:apache public_html/api/system/projects.php public_html/admin/projects.php tests/integration/ProjectInitApiTest.php && chmod 664 public_html/api/system/projects.php public_html/admin/projects.php tests/integration/ProjectInitApiTest.php
git add public_html/api/system/projects.php public_html/admin/projects.php tests/integration/ProjectInitApiTest.php
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): op=init API (gate+enqueue) + project wizard form"
```

---

## Task 8: job_project_init.sh 오케스트레이터

**Files:** Create `scripts/job_project_init.sh`, Modify `scripts/developers_worker.sh` (project_init dispatch)

이 단계는 외부 시스템(GitHub/Route53/site_manager/git)을 실제로 호출하므로 **bash 단위테스트는 하지 않고**, 얇은 오케스트레이터로 작성한다(각 단계는 Task 1–5 의 PHP 클래스가 책임). 실제 동작 검증은 Task 9 수동 e2e.

- [ ] **Step 1: Write `scripts/job_project_init.sh`** — receives `<job_id>`, loads job payload from DB via a small PHP entrypoint, runs the §4 orchestration order calling the PHP libs, accumulates result, sets projects active/failed. Concretely, implement the orchestration **in PHP** (a CLI script `scripts/run_project_init.php` invoked by the worker) so it can reuse the lib classes directly:

`scripts/run_project_init.php`:
```php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../public_html/config.php';
use Soritune\Developers\{CliRunner, GithubAdmin, Route53, SiteManagerClient, ProjectNaming, JobQueue};

$jobId = (int)($argv[1] ?? 0);
if ($jobId <= 0) { fwrite(STDERR, "usage: run_project_init.php <job_id>\n"); exit(1); }
$db = getDB();
$job = $db->query("SELECT * FROM jobs WHERE id=$jobId")->fetch(PDO::FETCH_ASSOC);
if (!$job) { fwrite(STDERR, "job not found\n"); exit(1); }
$p = json_decode($job['payload'], true);
$env = loadEnv();
$runner = new CliRunner();
$result = [];
$fail = function (string $step, string $err) use ($db, $jobId, &$result) {
    $result['failed_step'] = $step; $result['error'] = $err;
    JobQueue::markDone($jobId, false, "$step: $err", $result);
    echo "FAILED at $step: $err\n"; exit(1);
};

// 1. GitHub
$gh = new GithubAdmin($env['GITHUB_TOKEN'] ?? '', $env['GITHUB_ACCOUNT'] ?? '', $env['GITHUB_ACCOUNT_TYPE'] ?? 'user', $runner);
$repo = $gh->createRepo($p['slug'], $p['description'] ?? '');
if (!$repo['ok']) $fail('createRepo', $repo['error'] ?? '');
$result['repo'] = $repo; $full = $repo['full_name'];
$db->prepare("UPDATE projects SET github_repo=? WHERE id=?")->execute([$full, $p['project_id']]);
$br = $gh->createDevBranch($full); if (!$br['ok']) $fail('createDevBranch', $br['error'] ?? '');
$rs = $gh->addRulesets($full); if (!$rs['ok']) $fail('addRulesets', $rs['error'] ?? ''); $result['ruleset_ids']=$rs['ruleset_ids'];
// collaborators (non-fatal)
$memberUsers = [];
if (!empty($p['member_ids'])) {
    $in = implode(',', array_map('intval', $p['member_ids']));
    foreach ($db->query("SELECT github_username FROM users WHERE id IN ($in)")->fetchAll(PDO::FETCH_COLUMN) as $gu) {
        $memberUsers[] = (string)$gu;
    }
}
$result['collaborators'] = $gh->addCollaborators($full, $memberUsers);

// 2. Route53
$r53 = new Route53('3.37.213.224', $runner);
$d1 = $r53->upsertA($p['dev_subdomain']); if (!$d1['ok']) $fail('dns_dev', $d1['error'] ?? '');
$d2 = $r53->upsertA($p['prod_subdomain']); if (!$d2['ok']) $fail('dns_prod', $d2['error'] ?? '');
$result['dns'] = 'ok';

// 3. DNS propagation wait (non-fatal — see spec §4 step 3)
for ($i=0;$i<18;$i++){ $g=$runner->run('getent hosts '.escapeshellarg($p['dev_bare'].'.soritune.com').' 2>/dev/null'); if($g['code']===0)break; sleep(10); }

// 4-5. site_manager dev + prod
$smc = new SiteManagerClient('/var/www/html/_______site_SORITUNECOM_APP/jobs/pending','/var/www/html/_______site_SORITUNECOM_APP/jobs/done', 300, 3_000_000);
$pdev = $smc->provision($p['dev_bare']);  if (!$pdev['ok']) $fail('site_dev', $pdev['error'] ?? ''); $result['dev_site']=$pdev;
$pprod= $smc->provision($p['prod_bare']); if (!$pprod['ok']) $fail('site_prod', $pprod['error'] ?? ''); $result['prod_site']=$pprod;

// 6. clone into public_html (temp clone then move)
$devN = ProjectNaming::fromSubdomain($p['dev_subdomain']);
$prodN= ProjectNaming::fromSubdomain($p['prod_subdomain']);
$cloneInto = function(string $codeDir, string $branch) use ($runner,$env,$full,$fail){
    if (is_dir("$codeDir/.git")) return; // already cloned (idempotent)
    $tmp = sys_get_temp_dir().'/pcinit_'.bin2hex(random_bytes(5));
    $tokUrl = 'https://x-access-token:'.($env['GITHUB_TOKEN']??'').'@github.com/'.$full.'.git';
    // use credential via env to avoid token in argv/log:
    $cmd = 'GIT_TERMINAL_PROMPT=0 git -c credential.helper= clone --branch '.escapeshellarg($branch)
         .' '.escapeshellarg($tokUrl).' '.escapeshellarg($tmp).' 2>&1';
    $c = $runner->run($cmd);
    if ($c['code']!==0){ @shell_exec('rm -rf '.escapeshellarg($tmp)); $fail('clone_'.$branch, trim($c['out'])); }
    // move repo contents into codeDir (replacing site_manager default index.php), preserve nothing special in public_html
    $runner->run('rm -f '.escapeshellarg("$codeDir/index.php"));
    $runner->run('shopt -s dotglob; cp -a '.escapeshellarg($tmp).'/. '.escapeshellarg($codeDir).'/ 2>&1');
    $runner->run('rm -rf '.escapeshellarg($tmp));
};
$cloneInto($devN['code_dir'],'dev');
$cloneInto($prodN['code_dir'],'main');
$result['clone']='ok';

// 7. projects active
$devHead = trim($runner->run('git -C '.escapeshellarg($devN['code_dir']).' -c safe.directory='.escapeshellarg($devN['code_dir']).' rev-parse HEAD 2>/dev/null')['out']);
$prodHead= trim($runner->run('git -C '.escapeshellarg($prodN['code_dir']).' -c safe.directory='.escapeshellarg($prodN['code_dir']).' rev-parse HEAD 2>/dev/null')['out']);
$db->prepare("UPDATE projects SET status='active', last_synced_commit=?, last_prod_commit=? WHERE id=?")
   ->execute([$devHead?:null,$prodHead?:null,$p['project_id']]);
JobQueue::markDone($jobId, true, null, $result + ['repo_url'=>$repo['repo_url'] ?? '']);
echo "OK\n";
```
- [ ] **Step 2: Write thin `scripts/job_project_init.sh`** (the worker calls .sh; .sh calls the php):
```bash
#!/bin/bash
set -euo pipefail
SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
exec php "$SITE_ROOT/scripts/run_project_init.php" "$1"
```
- [ ] **Step 3: Wire into `scripts/developers_worker.sh`** — when a claimed job's type is `project_init`, run `scripts/job_project_init.sh <job_id>` instead of the stub markDone. Read current worker; add a type branch:
```php
// inside the drain loop, replace the unconditional markDone with:
if (($job['type'] ?? '') === 'project_init') {
    $rc = 0; passthru('bash '.escapeshellarg(__DIR__.'/job_project_init.sh').' '.(int)$job['id'], $rc);
    // run_project_init.php already calls markDone (success or fail); nothing else to do
} else {
    JobQueue::markDone((int)$job['id'], true, null, ['note'=>'Plan A stub - no handler']);
}
```
(Adapt to the actual worker.sh php -r block. Keep the per-job try/catch and the n>0 logging.)
- [ ] **Step 4: lint + full suite (no new phpunit; ensure nothing broke)**
```bash
php -l scripts/run_project_init.php && bash -n scripts/job_project_init.sh && bash -n scripts/developers_worker.sh
./vendor/bin/phpunit 2>&1 | tail -3
```
- [ ] **Step 5: ownership + commit**
```bash
chown ec2-user:apache scripts/run_project_init.php scripts/job_project_init.sh scripts/developers_worker.sh && chmod 664 scripts/run_project_init.php && chmod 775 scripts/job_project_init.sh scripts/developers_worker.sh
git add scripts/run_project_init.php scripts/job_project_init.sh scripts/developers_worker.sh
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): project_init orchestrator (run_project_init.php) + worker dispatch"
```

---

## Task 9: manual_rollback_project.sh + 수동 e2e

**Files:** Create `scripts/manual_rollback_project.sh`

- [ ] **Step 1: Write `scripts/manual_rollback_project.sh <slug>`** — best-effort 역순 정리:
```bash
#!/bin/bash
# Manual cleanup of a partially-created project. Best-effort: missing pieces skipped.
# Usage: manual_rollback_project.sh <slug>   (run as a user with the env + perms)
set -uo pipefail
SLUG="${1:?usage: manual_rollback_project.sh <slug>}"
ROOT="/var/www/html/_______site_SORITUNECOM_DEVELOPERS"
set -a; . "$ROOT/.env" 2>/dev/null || true; set +a
ACCT="${GITHUB_ACCOUNT:-}"
echo "Rolling back project '$SLUG' (account=$ACCT). Ctrl-C to abort."; sleep 3
# 1. GitHub repo (also removes rulesets)
[ -n "$ACCT" ] && GH_TOKEN="${GITHUB_TOKEN:-}" gh repo delete "$ACCT/$SLUG" --yes 2>&1 || echo "  repo skip"
# 2. Route53 A records — print instructions (deletion needs the exact record; left manual-safe)
echo "  (Route53) verify/delete A records dev-$SLUG.soritune.com / $SLUG.soritune.com if created"
# 3. site_manager backup_and_clean for dev + prod (via file queue)
for SUB in "dev-$SLUG" "$SLUG"; do
  ID="rb_$(date +%s)_$RANDOM"
  echo "{\"action\":\"backup_and_clean\",\"subdomain\":\"$SUB\"}" > "/var/www/html/_______site_SORITUNECOM_APP/jobs/pending/$ID.json" 2>/dev/null && echo "  queued backup_and_clean $SUB" || echo "  site_manager queue skip $SUB"
done
# 4. portal projects row → archived
mysql_root() { mysql -u root "$@"; }
echo "  Set projects.status='archived' for slug=$SLUG (run in portal DB)."
echo "Rollback queued. Check site_manager done/ and Route53 console."
```
(NOTE: keep it conservative — DB drops and dir removal are handled by site_manager `backup_and_clean`. Route53 deletion left as a printed instruction to avoid deleting the wrong record automatically. Refine during e2e.)
- [ ] **Step 2: ownership + commit (script only; no e2e yet)**
```bash
chown ec2-user:apache scripts/manual_rollback_project.sh && chmod 775 scripts/manual_rollback_project.sh
git add scripts/manual_rollback_project.sh
git -c user.name="박주희" -c user.email="soritunenglish@gmail.com" commit -m "feat(plan-c): manual_rollback_project.sh (best-effort cleanup)"
```
- [ ] **Step 3: 수동 e2e (PAT 준비된 뒤 — 사용자와 함께)** — phpunit 아님, 실제 1회:
  1. 사용자가 `.env` 에 실제 `GITHUB_ACCOUNT` + `GITHUB_TOKEN`(repo+admin 권한) 입력.
  2. binnie 로그인 → 프로젝트 → 새 프로젝트 마법사 (테스트 slug 예 `ctest`, dev `dev-ctest.soritune.com`, prod `ctest.soritune.com`).
  3. 작업 큐에서 project_init 진행 관찰. 성공 시: GitHub 에 repo+dev브랜치+ruleset 2개 확인, `dig dev-ctest.soritune.com`, https://dev-ctest.soritune.com 200, 디렉토리/DB 생성, projects active.
  4. **멱등 e2e**: 일부러 중간 실패(예: 잘못된 PAT 로 1회) 후 고치고 재실행 → 안 된 단계부터 이어서 완료되는지.
  5. 정리: `./scripts/manual_rollback_project.sh ctest` + Route53 레코드 확인 삭제.
- [ ] **Step 4: 메모리 업데이트** `/root/.claude/projects/-root/memory/project_developers_soritune_design_wip.md` 에 Plan C 완료 기록 (HEAD, 컴포넌트, e2e 결과, .env 시크릿 필요).

---

## Self-Review

**Spec coverage:**
- §3.1 GithubAdmin(repo 멱등/dev브랜치/ruleset 2/collaborator) → Task 3 ✓
- §3.2 Route53 UPSERT → Task 4 ✓
- §3.3 SiteManagerClient(파일큐, 멱등 provision) → Task 5 ✓
- §4 명명 도출(subdomain→dir/db) → Task 1 ProjectNaming ✓ ; 오케스트레이션 순서 → Task 8 ✓
- §3 .env 시크릿/마스킹 → Task 6 ✓
- op=init API + 마법사 → Task 7 ✓
- 실패처리/멱등/롤백 → Task 8 fail 핸들러 + Task 9 ✓
- 테스트(멱등 재실행 포함) → 각 unit test + Task 9 e2e ✓

**Placeholder scan:** 없음. (이전 초안의 `$GLOBALS['__init_repo']` 임시코드는 Task 7 Step 3 에서 `loadEnv()['GITHUB_ACCOUNT']` 기반 실제 코드로 교체 완료.)

**Type consistency:** GithubAdmin.createRepo→{ok,created,full_name,repo_url}, addRulesets→{ok,ruleset_ids}, Route53.upsertA→{ok,fqdn}, SiteManagerClient.provision→{ok,skipped}/{ok:false,step,error}, ProjectNaming.fromSubdomain→{bare,derived,site_dir,code_dir,db_name}. run_project_init.php·테스트가 동일 키 사용. JobQueue.enqueue(type,payload,userId,projectId) Plan A 시그니처 일치.

**알려진 주의:** dir/db 는 subdomain 도출(slug 아님). projects.dev_dir = SITE_DIR/public_html(.git 위치, GitInspector 정합). 토큰은 argv/log 노출 금지(env/credential). bash 출력 batch 지연 — 구조화 토큰 신뢰. 실제 외부생성은 phpunit 금지(수동 e2e만).
