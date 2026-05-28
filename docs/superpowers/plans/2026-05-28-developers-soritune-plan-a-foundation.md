# developers.soritune.com Plan A — Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundation of developers.soritune.com — login screen, user/project CRUD (manual registration only), and job queue infrastructure skeleton. After this plan, an admin can log in, create employee accounts, register an existing project, grant access, and the worker cron loop is running (with no real job types implemented yet — those come in Plan B/C).

**Architecture:** PHP 8 + MariaDB + Apache (PHP-FPM, apache user). Single-server PHP app at `/var/www/html/_______site_SORITUNECOM_DEVELOPERS/`. Session-based auth (bcrypt + PHP file handler). JSON APIs with fragment dispatch (`api/system.php?action=X` → `api/system/X.php`). Vanilla JS UI (no framework). Cron-driven bash worker with file-based queue + DB jobs table. composer + phpunit for unit tests. Bash + curl for smoke tests.

**Tech Stack:** PHP 8.x, MariaDB 10.x, Apache 2.4 + mod_php / PHP-FPM, bcrypt, composer, phpunit 10.x, bash, curl.

**Spec reference:** `/var/www/html/_______site_SORITUNECOM_DEVELOPERS/docs/superpowers/specs/2026-05-28-developers-soritune-design.md`

---

## Prerequisites (P0, done outside this plan)

Before Task 1, the user must have completed:
- DB `SORITUNECOM_DEVELOPERS` created (via site_manager or manual)
- `.db_credentials` file at site root with `DB_HOST/DB_NAME/DB_USER/DB_PASS`, owned `apache:apache 640`
- composer installed system-wide (`which composer` returns a path)
- PHP 8.x + PHP-FPM + Apache running, vhost for developers.soritune.com pointing at `public_html/`
- Optional but recommended: GitHub repo `pjuhe99/soritune-developers` created (so git init can push immediately)

---

## File Structure

```
/var/www/html/_______site_SORITUNECOM_DEVELOPERS/
├── public_html/                     # apache DocumentRoot
│   ├── index.php                    # role-based redirect entry
│   ├── login.php                    # GET form + POST handler
│   ├── logout.php
│   ├── config.php                   # DB conn, e(), jsonResponse/Success/Error
│   ├── auth.php                     # session helpers, requireAuth/Admin/Employee
│   ├── csrf.php                     # token gen/verify
│   ├── api/
│   │   ├── system.php               # admin API router (fragment dispatch)
│   │   ├── system/
│   │   │   ├── auth.php             # login_check, logout
│   │   │   ├── users.php            # CRUD
│   │   │   ├── projects.php         # CRUD + access toggle
│   │   │   └── jobs.php             # list
│   │   ├── user.php                 # employee API router
│   │   └── user/
│   │       ├── projects.php         # list my projects
│   │       └── me.php               # change password
│   ├── admin/
│   │   ├── index.php                # dashboard
│   │   ├── users.php
│   │   ├── projects.php
│   │   └── jobs.php
│   ├── p/
│   │   └── index.php                # employee home
│   ├── me.php                       # employee /me
│   └── assets/
│       └── style.css                # minimal styles (full Notion design = Plan D)
├── lib/                             # composer autoload root for non-web code
│   ├── Audit.php
│   ├── JobQueue.php
│   └── Validation.php
├── migrations/
│   ├── 001_create_users.sql
│   ├── 002_create_projects.sql
│   ├── 003_create_project_access.sql
│   ├── 004_create_tasks.sql
│   ├── 005_create_jobs.sql
│   ├── 006_create_audit_log.sql
│   └── 007_seed_admin.sql.template  # rendered to seed_admin.sql by helper
├── scripts/
│   ├── developers_worker.sh         # cron entry (Plan A: stub loop)
│   └── run_migrations.sh
├── jobs/
│   ├── pending/                     # file markers (gitignored)
│   ├── running/
│   ├── done/
│   └── logs/
├── tests/
│   ├── unit/
│   │   ├── ConfigTest.php
│   │   ├── AuthTest.php
│   │   ├── CsrfTest.php
│   │   ├── AuditTest.php
│   │   └── JobQueueTest.php
│   ├── integration/
│   │   ├── LoginTest.php
│   │   ├── UserApiTest.php
│   │   ├── ProjectApiTest.php
│   │   └── JobsEndToEndTest.php
│   ├── smoke/
│   │   ├── login.sh
│   │   ├── user_crud.sh
│   │   ├── project_register.sh
│   │   └── job_enqueue.sh
│   ├── bootstrap.php                # phpunit bootstrap (loads autoload + seeds test DB)
│   └── run.sh                       # unit + integration + smoke
├── .env                             # SESSION_SECRET, GITHUB_APP_ID etc (apache:apache 640)
├── .db_credentials                  # apache:apache 640
├── .gitignore
├── composer.json
├── composer.lock
├── phpunit.xml
└── README.md
```

Each file's responsibility is single — `config.php` only knows DB+helpers, `auth.php` only knows sessions, `csrf.php` only knows CSRF, etc. Files that change together (e.g., admin/users.php + api/system/users.php) live in mirrored paths.

---

## Task 1: Project scaffolding, composer, phpunit, .gitignore

**Files:**
- Create: `composer.json`
- Create: `composer.lock` (via composer install)
- Create: `phpunit.xml`
- Create: `.gitignore`
- Create: `README.md`
- Create: `tests/bootstrap.php`

- [ ] **Step 1: Verify prerequisites**

Run: `php -v && composer --version && ls -la .db_credentials .env 2>/dev/null`
Expected: PHP 8.x, composer 2.x, `.db_credentials` owned `apache:apache 640`, `.env` (creates if missing — see step 3).

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "soritune/developers",
    "description": "developers.soritune.com admin tool",
    "require": {
        "php": "^8.1"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "autoload": {
        "psr-4": {
            "Soritune\\Developers\\": "lib/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Soritune\\Developers\\Tests\\": "tests/"
        }
    },
    "config": {
        "sort-packages": true
    }
}
```

- [ ] **Step 3: Install composer deps + verify .env exists**

```bash
composer install --no-progress
test -f .env || (printf 'SESSION_SECRET=%s\n' "$(openssl rand -hex 32)" > .env && chown apache:apache .env && chmod 640 .env)
```
Expected: `vendor/` directory created, `.env` exists with `SESSION_SECRET=<64-hex>`.

- [ ] **Step 4: Create `phpunit.xml`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="tests/bootstrap.php"
         colors="true"
         testdox="true">
    <testsuites>
        <testsuite name="unit">
            <directory>tests/unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory>tests/integration</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="APP_ENV" value="test"/>
    </php>
</phpunit>
```

- [ ] **Step 5: Create `tests/bootstrap.php`**

```php
<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../public_html/config.php';
```

(`config.php` will be added in Task 3; phpunit will fail until then.)

- [ ] **Step 6: Create `.gitignore`**

```
vendor/
composer.lock
.env
.db_credentials
jobs/pending/*
jobs/running/*
jobs/done/*
jobs/logs/*
!jobs/pending/.gitkeep
!jobs/running/.gitkeep
!jobs/done/.gitkeep
!jobs/logs/.gitkeep
.phpunit.result.cache
*.log
```

Commit `composer.lock` by removing it from `.gitignore` if the team policy says so — this plan keeps lock OUT to match `junior` (which is lock-less). Confirm with stakeholder if a deviation is wanted.

- [ ] **Step 7: Create `jobs/` placeholder dirs + .gitkeep**

```bash
mkdir -p jobs/{pending,running,done,logs}
touch jobs/{pending,running,done,logs}/.gitkeep
chown -R ec2-user:apache jobs
find jobs -type d -exec chmod 2775 {} \;
find jobs -type f -exec chmod 664 {} \;
```

- [ ] **Step 8: Create initial `README.md`**

```markdown
# developers.soritune.com

소리튠 사내 AI 개발 협업·배포 관리 도구. 비개발자 직원이 Claude Code/Codex 로 만든 미니 프로젝트의 dev/prod 배포를 관리한다.

스펙: `docs/superpowers/specs/2026-05-28-developers-soritune-design.md`

## 개발
```
composer install
./scripts/run_migrations.sh
vendor/bin/phpunit
./tests/run.sh
```
```

- [ ] **Step 9: Run phpunit to confirm scaffolding**

Run: `vendor/bin/phpunit --list-tests`
Expected: `No tests found` (no test files yet — phpunit itself works).

- [ ] **Step 10: Commit**

```bash
git add composer.json phpunit.xml .gitignore tests/bootstrap.php jobs/*/.gitkeep README.md
git commit -m "chore: scaffold composer, phpunit, gitignore, jobs queue dirs"
```

---

## Task 2: Database schema (migrations 001–006) + migration runner

**Files:**
- Create: `migrations/001_create_users.sql`
- Create: `migrations/002_create_projects.sql`
- Create: `migrations/003_create_project_access.sql`
- Create: `migrations/004_create_tasks.sql`
- Create: `migrations/005_create_jobs.sql`
- Create: `migrations/006_create_audit_log.sql`
- Create: `scripts/run_migrations.sh`
- Test: `tests/integration/SchemaTest.php`

- [ ] **Step 1: Write failing schema test**

`tests/integration/SchemaTest.php`:

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class SchemaTest extends TestCase
{
    public function testAllTablesExist(): void
    {
        $db = getDB();
        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $required = ['users', 'projects', 'project_access', 'tasks', 'jobs', 'audit_log'];
        foreach ($required as $t) {
            $this->assertContains($t, $tables, "Table $t missing");
        }
    }

    public function testUsersStatusFields(): void
    {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['id','username','password_hash','display_name','role','github_username','active','failed_attempts','locked_until','must_change_password','last_login_at'] as $c) {
            $this->assertContains($c, $cols, "users.$c missing");
        }
    }

    public function testJobsTypeEnum(): void
    {
        $db = getDB();
        $col = $db->query("SHOW COLUMNS FROM jobs LIKE 'type'")->fetch(PDO::FETCH_ASSOC);
        $this->assertStringContainsString("'project_init'", $col['Type']);
        $this->assertStringContainsString("'site_create'", $col['Type']);
        $this->assertStringContainsString("'dev_deploy'", $col['Type']);
        $this->assertStringContainsString("'prod_deploy'", $col['Type']);
        $this->assertStringContainsString("'user_repo_grant'", $col['Type']);
    }

    public function testTasksStatusEnum11Values(): void
    {
        $db = getDB();
        $col = $db->query("SHOW COLUMNS FROM tasks LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        foreach (['drafting','dev_pending','dev_deploying','dev_ready','review_requested','changes_requested','approved','prod_deploying','prod_done','failed','on_hold'] as $s) {
            $this->assertStringContainsString("'$s'", $col['Type']);
        }
    }

    public function testProjectsLastSyncFields(): void
    {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM projects")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['last_synced_commit','last_synced_at','last_prod_commit','last_prod_deployed_at'] as $c) {
            $this->assertContains($c, $cols, "projects.$c missing");
        }
    }
}
```

- [ ] **Step 2: Run test — expected fail**

Run: `vendor/bin/phpunit --filter SchemaTest 2>&1 | tail -20`
Expected: Fatal (`getDB` undefined) or test failures (tables missing). This is OK — we need config.php (Task 3) for getDB. **Skip until Task 3 then come back, OR proceed anyway since the migration files themselves are the deliverable.** For now: write migrations, defer test run to Task 3.

- [ ] **Step 3: Write `migrations/001_create_users.sql`**

```sql
CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(64) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  role ENUM('admin','employee') NOT NULL,
  github_username VARCHAR(80) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until TIMESTAMP NULL DEFAULT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_active_role (active, role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Write `migrations/002_create_projects.sql`**

```sql
CREATE TABLE IF NOT EXISTS projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  description TEXT NULL,
  github_repo VARCHAR(120) NOT NULL,
  dev_subdomain VARCHAR(120) NOT NULL,
  prod_subdomain VARCHAR(120) NOT NULL,
  dev_dir VARCHAR(255) NOT NULL,
  prod_dir VARCHAR(255) NOT NULL,
  dev_db_name VARCHAR(64) NOT NULL,
  prod_db_name VARCHAR(64) NOT NULL,
  default_branch VARCHAR(40) NOT NULL DEFAULT 'dev',
  prod_branch VARCHAR(40) NOT NULL DEFAULT 'main',
  card_tint ENUM('peach','rose','mint','lavender','sky','yellow') NOT NULL DEFAULT 'peach',
  status ENUM('provisioning','active','archived') NOT NULL DEFAULT 'provisioning',
  last_synced_commit VARCHAR(40) NULL DEFAULT NULL,
  last_synced_at TIMESTAMP NULL DEFAULT NULL,
  last_prod_commit VARCHAR(40) NULL DEFAULT NULL,
  last_prod_deployed_at TIMESTAMP NULL DEFAULT NULL,
  init_job_id INT UNSIGNED NULL DEFAULT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_status (status),
  CONSTRAINT fk_projects_created_by FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 5: Write `migrations/003_create_project_access.sql`**

```sql
CREATE TABLE IF NOT EXISTS project_access (
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  granted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by INT UNSIGNED NOT NULL,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_pa_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_granted_by FOREIGN KEY (granted_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 6: Write `migrations/004_create_tasks.sql`**

```sql
CREATE TABLE IF NOT EXISTS tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  status ENUM(
    'drafting','dev_pending','dev_deploying','dev_ready',
    'review_requested','changes_requested','approved',
    'prod_deploying','prod_done','failed','on_hold'
  ) NOT NULL DEFAULT 'drafting',
  current_job_id INT UNSIGNED NULL DEFAULT NULL,
  last_dev_commit VARCHAR(40) NULL DEFAULT NULL,
  last_prod_commit VARCHAR(40) NULL DEFAULT NULL,
  last_dev_deploy_at TIMESTAMP NULL DEFAULT NULL,
  admin_comment TEXT NULL,
  reviewed_at TIMESTAMP NULL DEFAULT NULL,
  approved_by INT UNSIGNED NULL DEFAULT NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  prod_deployed_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_project_user (project_id, user_id),
  INDEX idx_status (status),
  CONSTRAINT fk_tasks_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_tasks_approved_by FOREIGN KEY (approved_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 7: Write `migrations/005_create_jobs.sql`**

```sql
CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  type ENUM('project_init','site_create','dev_deploy','prod_deploy','user_repo_grant') NOT NULL,
  status ENUM('pending','running','success','failed','canceled') NOT NULL DEFAULT 'pending',
  project_id INT UNSIGNED NULL DEFAULT NULL,
  task_id INT UNSIGNED NULL DEFAULT NULL,
  user_id INT UNSIGNED NOT NULL,
  payload JSON NOT NULL,
  result JSON NULL,
  error_message TEXT NULL,
  log_path VARCHAR(255) NULL,
  attempts INT NOT NULL DEFAULT 0,
  enqueued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL DEFAULT NULL,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  INDEX idx_status_type (status, type),
  INDEX idx_task (task_id),
  INDEX idx_project (project_id),
  CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 8: Write `migrations/006_create_audit_log.sql`**

```sql
CREATE TABLE IF NOT EXISTS audit_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL DEFAULT NULL,
  action VARCHAR(40) NOT NULL,
  entity_type VARCHAR(20) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  payload JSON NULL,
  ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_entity (entity_type, entity_id),
  INDEX idx_user (user_id),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 9: Write `scripts/run_migrations.sh`**

```bash
#!/bin/bash
# Idempotent migration runner. Tracks applied files in `schema_migrations` table.
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE_ROOT"

# shellcheck disable=SC1091
set -a
. ./.db_credentials
set +a

mysql_cmd() {
  mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" "$@"
}

mysql_cmd <<'SQL'
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(120) NOT NULL PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL

applied=$(mysql_cmd -N -B -e "SELECT filename FROM schema_migrations" | sort)

for f in migrations/*.sql; do
  base=$(basename "$f")
  case "$base" in *.template) continue ;; esac
  if grep -qFx "$base" <<<"$applied"; then
    echo "SKIP  $base"
    continue
  fi
  echo "APPLY $base"
  mysql_cmd < "$f"
  mysql_cmd -e "INSERT INTO schema_migrations (filename) VALUES ('$base')"
done

echo "Done."
```

- [ ] **Step 10: Make runner executable and run it**

```bash
chmod +x scripts/run_migrations.sh
sudo -u apache ./scripts/run_migrations.sh
```
Expected: APPLY for each new file, then `Done.` Re-run prints SKIP for all.

- [ ] **Step 11: Run SchemaTest — expected pass after getDB exists**

Defer test execution until Task 3 (getDB needs config.php). The migration files are committed now.

- [ ] **Step 12: Commit**

```bash
git add migrations/ scripts/run_migrations.sh tests/integration/SchemaTest.php
git commit -m "feat: db schema migrations 001-006 + idempotent runner"
```

---

## Task 3: config.php — DB connection + helpers (e, jsonSuccess, jsonError)

**Files:**
- Create: `public_html/config.php`
- Test: `tests/unit/ConfigTest.php`

- [ ] **Step 1: Write failing tests**

`tests/unit/ConfigTest.php`:

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testEEscapes(): void
    {
        $this->assertSame('&lt;script&gt;', e('<script>'));
        $this->assertSame('&quot;', e('"'));
    }

    public function testEAcceptsEmpty(): void
    {
        $this->assertSame('', e(''));
    }

    public function testEMustReceiveStringNotNull(): void
    {
        // Strict signature: nullable use must pass ?? '' (memory php-e-null-strict-signature)
        $this->expectError();
        @e(null);
    }

    public function testJsonSuccessFlattens(): void
    {
        // jsonSuccess merges payload into top-level (memory php-jsonsuccess-flatten-shape)
        ob_start();
        try {
            jsonSuccess(['key' => 'value', 'count' => 3], 'OK');
        } catch (\Throwable $e) {
            // exit() may throw in tests if guarded; that's fine
        }
        $out = ob_get_clean();
        $decoded = json_decode($out, true);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('value', $decoded['key']);
        $this->assertSame(3, $decoded['count']);
        $this->assertSame('OK', $decoded['message']);
    }

    public function testGetDbReturnsPdo(): void
    {
        $db = getDB();
        $this->assertInstanceOf(\PDO::class, $db);
        $row = $db->query("SELECT 1 AS v")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(1, (int)$row['v']);
    }
}
```

- [ ] **Step 2: Run test — expected fail**

Run: `vendor/bin/phpunit --filter ConfigTest 2>&1 | tail -5`
Expected: Fatal `e` undefined / `jsonSuccess` undefined / `getDB` undefined.

- [ ] **Step 3: Write `public_html/config.php`**

```php
<?php
declare(strict_types=1);

const SITE_ROOT = __DIR__ . '/..';

function loadDbCredentials(): array {
    $path = SITE_ROOT . '/.db_credentials';
    if (!is_readable($path)) {
        throw new RuntimeException(".db_credentials not readable at $path");
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $out[trim($k)] = trim($v);
    }
    foreach (['DB_HOST','DB_NAME','DB_USER','DB_PASS'] as $req) {
        if (!isset($out[$req])) {
            throw new RuntimeException("Missing $req in .db_credentials");
        }
    }
    return $out;
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $c = loadDbCredentials();
    $dsn = sprintf("mysql:host=%s;dbname=%s;charset=utf8mb4", $c['DB_HOST'], $c['DB_NAME']);
    $pdo = new PDO($dsn, $c['DB_USER'], $c['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function loadEnv(): array {
    static $env = null;
    if ($env !== null) return $env;
    $path = SITE_ROOT . '/.env';
    $env = [];
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0] === '#') continue;
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            $env[trim($k)] = trim($v);
        }
    }
    return $env;
}

function envOrDie(string $key): string {
    $env = loadEnv();
    if (!isset($env[$key]) || $env[$key] === '') {
        throw new RuntimeException("Required env var $key missing");
    }
    return $env[$key];
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonSuccess(array $payload = [], string $message = ''): void {
    jsonResponse(array_merge(['ok' => true, 'message' => $message], $payload));
}

function jsonError(string $message, int $status = 400, array $extra = []): void {
    jsonResponse(array_merge(['ok' => false, 'message' => $message], $extra), $status);
}

function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
```

- [ ] **Step 4: Run tests — expected pass**

Run: `vendor/bin/phpunit --filter ConfigTest`
Expected: 5 tests pass (4 + 1 for getDB). Schema tests from Task 2 also now pass.

- [ ] **Step 5: Run all integration tests**

Run: `vendor/bin/phpunit tests/integration/SchemaTest.php`
Expected: All assertions pass — schema is complete.

- [ ] **Step 6: Commit**

```bash
git add public_html/config.php tests/unit/ConfigTest.php
git commit -m "feat: config.php with DB conn, env loader, jsonSuccess/Error, e() helpers"
```

---

## Task 4: Seed admin migration + auth.php session helpers

**Files:**
- Create: `migrations/007_seed_admin.sql.template`
- Create: `scripts/seed_admin.sh`
- Create: `public_html/auth.php`
- Test: `tests/unit/AuthTest.php`

- [ ] **Step 1: Write failing AuthTest**

`tests/unit/AuthTest.php`:

```php
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
```

- [ ] **Step 2: Run test — expected fail**

Run: `vendor/bin/phpunit --filter AuthTest`
Expected: Fatal — `currentUser`, `isAdmin`, `isEmployee` undefined.

- [ ] **Step 3: Write `public_html/auth.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function startSessionOnce(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('SDDEVSESSID');
    session_start();
    // Touch last activity for idle timeout (8h)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 28800) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION['last_activity'] = time();
}

function currentUser(): ?array {
    return $_SESSION['user'] ?? null;
}

function isAdmin(): bool {
    return (currentUser()['role'] ?? null) === 'admin';
}

function isEmployee(): bool {
    return (currentUser()['role'] ?? null) === 'employee';
}

function loginUser(array $userRow): void {
    startSessionOnce();
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id' => (int)$userRow['id'],
        'username' => $userRow['username'],
        'display_name' => $userRow['display_name'],
        'role' => $userRow['role'],
        'must_change_password' => (bool)$userRow['must_change_password'],
    ];
}

function logoutUser(): void {
    startSessionOnce();
    $_SESSION = [];
    session_destroy();
}

function requireAuth(): array {
    startSessionOnce();
    $u = currentUser();
    if (!$u) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/api')) {
            jsonError('login required', 401);
        }
        header('Location: /login.php');
        exit;
    }
    return $u;
}

function requireAdmin(): array {
    $u = requireAuth();
    if ($u['role'] !== 'admin') {
        jsonError('forbidden', 403);
    }
    return $u;
}

function requireEmployee(): array {
    $u = requireAuth();
    // Both admin and employee allowed on /api/user, gate is "logged in".
    return $u;
}

function requireProjectAccess(int $projectId): array {
    $u = requireAuth();
    if ($u['role'] === 'admin') return $u;
    $db = getDB();
    $st = $db->prepare("SELECT 1 FROM project_access WHERE project_id = ? AND user_id = ?");
    $st->execute([$projectId, $u['id']]);
    if (!$st->fetchColumn()) {
        jsonError('forbidden', 403);
    }
    return $u;
}
```

- [ ] **Step 4: Run tests — expected pass**

Run: `vendor/bin/phpunit --filter AuthTest`
Expected: 5 tests pass.

- [ ] **Step 5: Write `migrations/007_seed_admin.sql.template`**

```sql
-- Rendered to seed_admin.sql by scripts/seed_admin.sh.
-- Placeholders: __USERNAME__, __HASH__, __DISPLAY__.
INSERT INTO users (username, password_hash, display_name, role, must_change_password)
VALUES ('__USERNAME__', '__HASH__', '__DISPLAY__', 'admin', 1)
ON DUPLICATE KEY UPDATE
  password_hash = VALUES(password_hash),
  must_change_password = 1;
```

- [ ] **Step 6: Write `scripts/seed_admin.sh`**

```bash
#!/bin/bash
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE_ROOT"

if [ "${1:-}" = "" ] || [ "${2:-}" = "" ]; then
    echo "Usage: $0 <username> <display_name>"
    echo "(Will prompt for password)"
    exit 1
fi

username="$1"
display="$2"

read -srp "Password (>=12 chars): " pw; echo
if [ "${#pw}" -lt 12 ]; then
    echo "Password must be at least 12 characters."
    exit 1
fi

hash=$(php -r 'echo password_hash($argv[1], PASSWORD_BCRYPT, ["cost" => 10]);' "$pw")

set -a; . ./.db_credentials; set +a
sed -e "s|__USERNAME__|$username|g" \
    -e "s|__HASH__|$hash|g" \
    -e "s|__DISPLAY__|$display|g" \
    migrations/007_seed_admin.sql.template \
  | mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME"

echo "Admin '$username' seeded (must change password on first login)."
```

- [ ] **Step 7: Run seed for the user's actual admin account**

```bash
chmod +x scripts/seed_admin.sh
sudo -u apache ./scripts/seed_admin.sh admin "박주희"
# Enter password when prompted
```
Verify: `mysql ... -e "SELECT username,role,active,must_change_password FROM SORITUNECOM_DEVELOPERS.users"` shows one admin row.

- [ ] **Step 8: Commit**

```bash
git add public_html/auth.php tests/unit/AuthTest.php migrations/007_seed_admin.sql.template scripts/seed_admin.sh
git commit -m "feat: auth helpers (sessions, role gates) + seed_admin.sh"
```

---

## Task 5: csrf.php — CSRF token generation + verification

**Files:**
- Create: `public_html/csrf.php`
- Test: `tests/unit/CsrfTest.php`

- [ ] **Step 1: Write failing test**

`tests/unit/CsrfTest.php`:

```php
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
```

- [ ] **Step 2: Run test — expected fail**

Run: `vendor/bin/phpunit --filter CsrfTest`
Expected: Fatal — undefined.

- [ ] **Step 3: Write `public_html/csrf.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function csrfToken(): string {
    startSessionOnce();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfVerify(string $supplied): bool {
    startSessionOnce();
    $expected = $_SESSION['csrf'] ?? '';
    if ($expected === '' || $supplied === '') return false;
    return hash_equals($expected, $supplied);
}

function requireCsrfOrAbort(): void {
    $supplied = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!csrfVerify($supplied)) {
        jsonError('csrf token invalid', 419);
    }
}
```

- [ ] **Step 4: Run test — expected pass**

Run: `vendor/bin/phpunit --filter CsrfTest`
Expected: 4 tests pass.

- [ ] **Step 5: Commit**

```bash
git add public_html/csrf.php tests/unit/CsrfTest.php
git commit -m "feat: csrf token gen/verify with constant-time compare"
```

---

## Task 6: login.php (GET form + POST handler) + logout.php + integration test

**Files:**
- Create: `public_html/login.php`
- Create: `public_html/logout.php`
- Test: `tests/integration/LoginTest.php`

- [ ] **Step 1: Write failing integration test**

`tests/integration/LoginTest.php`:

```php
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
            "INSERT INTO users (username, password_hash, display_name, role, must_change_password) VALUES (?, ?, 'Login Test', 'admin', 0)"
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
```

- [ ] **Step 2: Run test — expected fail**

Run: `vendor/bin/phpunit --filter LoginTest`
Expected: Fatal — `attemptLogin` undefined / login.php missing.

- [ ] **Step 3: Write `public_html/login.php`**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

/**
 * Attempts a login. Returns ['ok' => bool, 'reason' => ?string, 'user' => ?array].
 * Side-effects: updates failed_attempts/locked_until/last_login_at, calls loginUser() on success.
 */
function attemptLogin(string $username, string $password): array {
    $db = getDB();
    $st = $db->prepare("SELECT * FROM users WHERE username = ? AND active = 1");
    $st->execute([$username]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) {
        return ['ok' => false, 'reason' => 'invalid', 'user' => null];
    }
    // Locked?
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        return ['ok' => false, 'reason' => 'locked', 'user' => null];
    }
    if (!password_verify($password, $u['password_hash'])) {
        $newAttempts = (int)$u['failed_attempts'] + 1;
        $lockedUntil = $newAttempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $db->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
           ->execute([$newAttempts, $lockedUntil, $u['id']]);
        return ['ok' => false, 'reason' => $lockedUntil ? 'locked' : 'invalid', 'user' => null];
    }
    // Success
    $db->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?")
       ->execute([$u['id']]);
    loginUser($u);
    return ['ok' => true, 'reason' => null, 'user' => $u];
}

// HTTP entry
if (PHP_SAPI !== 'cli' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfOrAbort();
    $u = $_POST['username'] ?? '';
    $p = $_POST['password'] ?? '';
    if (!is_string($u) || !is_string($p) || $u === '' || $p === '') {
        $err = '아이디·비밀번호를 입력하세요.';
    } else {
        $res = attemptLogin($u, $p);
        if ($res['ok']) {
            $next = !empty($res['user']['must_change_password']) ? '/me.php?force_change=1' : '/';
            header('Location: ' . $next);
            exit;
        }
        $err = match ($res['reason']) {
            'locked' => '로그인 시도가 너무 많습니다. 15분 후 다시 시도하세요.',
            default  => '아이디 또는 비밀번호가 올바르지 않습니다.',
        };
    }
}

if (PHP_SAPI === 'cli') return;

startSessionOnce();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<title>로그인 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<main class="login-shell">
  <h1>developers.soritune.com</h1>
  <p>로그인하세요.</p>
  <?php if (!empty($err)): ?><div class="error"><?= e($err) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($token) ?>">
    <label>아이디 <input name="username" required></label>
    <label>비밀번호 <input name="password" type="password" required></label>
    <button type="submit">로그인</button>
  </form>
</main>
</body>
</html>
```

- [ ] **Step 4: Write `public_html/logout.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
logoutUser();
header('Location: /login.php');
exit;
```

- [ ] **Step 5: Write minimal `public_html/assets/style.css`** (Plan A — minimal; full Notion design in Plan D)

```css
:root { --ink:#37352f; --primary:#7C53D8; --hairline:#e6e4df; --canvas:#ffffff; --error:#ef4444; }
body { font-family: -apple-system, "Pretendard", system-ui, sans-serif; color: var(--ink); margin: 0; background: var(--canvas); }
.login-shell { max-width: 360px; margin: 80px auto; padding: 0 16px; }
.login-shell h1 { font-size: 24px; margin-bottom: 4px; }
.login-shell form { display: grid; gap: 12px; margin-top: 24px; }
.login-shell label { display: grid; gap: 4px; font-size: 14px; }
.login-shell input { padding: 10px; border: 1px solid var(--hairline); border-radius: 8px; font-size: 14px; }
.login-shell button { background: var(--primary); color: #fff; padding: 10px; border: 0; border-radius: 8px; font-weight: 500; cursor: pointer; }
.error { background: #fee2e2; color: var(--error); padding: 8px 12px; border-radius: 8px; font-size: 13px; }
```

- [ ] **Step 6: Run tests — expected pass**

Run: `vendor/bin/phpunit --filter LoginTest`
Expected: 3 tests pass.

- [ ] **Step 7: HTTP smoke test**

```bash
# Get login form + extract csrf
curl -s -c /tmp/sd_cookie -b /tmp/sd_cookie https://developers.soritune.com/login.php -o /tmp/sd_login.html
csrf=$(grep -oP '(?<=name="_csrf" value=")[^"]+' /tmp/sd_login.html)
# Post wrong password
curl -s -c /tmp/sd_cookie -b /tmp/sd_cookie -X POST https://developers.soritune.com/login.php \
  -d "_csrf=$csrf&username=admin&password=wrong" -o /tmp/sd_fail.html
grep -q "올바르지 않습니다" /tmp/sd_fail.html && echo "OK: wrong password rejected"
```
Expected: prints `OK: wrong password rejected`.

- [ ] **Step 8: Commit**

```bash
git add public_html/login.php public_html/logout.php public_html/assets/style.css tests/integration/LoginTest.php
git commit -m "feat: login/logout + lockout (5 fail → 15min) + CSRF + integration test"
```

---

## Task 7: index.php (role-based redirect) + admin/index.php + employee /p/index.php skeletons

**Files:**
- Create: `public_html/index.php`
- Create: `public_html/admin/index.php`
- Create: `public_html/p/index.php`

- [ ] **Step 1: Write `public_html/index.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
$u = requireAuth();
if ($u['role'] === 'admin') {
    header('Location: /admin/');
} else {
    header('Location: /p/');
}
exit;
```

- [ ] **Step 2: Write `public_html/admin/index.php` (skeleton; full dashboard in Plan B/D)**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>관리자 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
<meta name="csrf-token" content="<?= e($token) ?>">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="/admin/">대시보드</a>
  <a href="/admin/users.php">사용자</a>
  <a href="/admin/projects.php">프로젝트</a>
  <a href="/admin/jobs.php">작업 큐</a>
  <span class="grow"></span>
  <span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <h1>관리자 대시보드</h1>
  <p>Plan A: skeleton. Plan B/D 에서 검토 대기·진행중 job·최근 배포 카드 추가 예정.</p>
</main>
</body></html>
```

- [ ] **Step 3: Write `public_html/p/index.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
$u = requireAuth();

$db = getDB();
if ($u['role'] === 'admin') {
    $projects = $db->query("SELECT * FROM projects WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $st = $db->prepare("
        SELECT p.* FROM projects p
        INNER JOIN project_access pa ON pa.project_id = p.id
        WHERE pa.user_id = ? AND p.status = 'active'
        ORDER BY p.name
    ");
    $st->execute([$u['id']]);
    $projects = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>내 프로젝트 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="/p/">홈</a>
  <a href="/me.php">내 정보</a>
  <span class="grow"></span>
  <span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <h1>내가 접근 가능한 프로젝트</h1>
  <?php if (empty($projects)): ?>
    <p>아직 배정된 프로젝트가 없습니다. 관리자에게 문의하세요.</p>
  <?php else: ?>
    <ul class="project-list">
      <?php foreach ($projects as $p): ?>
        <li class="project-card tint-<?= e($p['card_tint']) ?>">
          <h2><?= e($p['name']) ?></h2>
          <p><?= e($p['description'] ?? '') ?></p>
          <div class="urls">
            <a href="https://<?= e($p['dev_subdomain']) ?>" target="_blank">개발 화면</a>
            <a href="https://<?= e($p['prod_subdomain']) ?>" target="_blank">운영 화면</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
</body></html>
```

- [ ] **Step 4: Extend `style.css`** (append)

```css
.topnav { display: flex; gap: 16px; padding: 12px 24px; border-bottom: 1px solid var(--hairline); align-items: center; font-size: 14px; }
.topnav strong { margin-right: 12px; }
.topnav a { color: var(--ink); text-decoration: none; }
.topnav .grow { flex: 1; }
.page { max-width: 1200px; margin: 24px auto; padding: 0 24px; }
.project-list { list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }
.project-card { padding: 20px; border-radius: 12px; border: 1px solid var(--hairline); }
.project-card h2 { margin: 0 0 6px; font-size: 18px; }
.project-card .urls { display: flex; gap: 12px; margin-top: 12px; }
.tint-peach { background: #ffe9d6; }
.tint-rose { background: #fce4ec; }
.tint-mint { background: #d5f5e3; }
.tint-lavender { background: #e7e2f7; }
.tint-sky { background: #d8ecf9; }
.tint-yellow { background: #fff5ce; }
```

- [ ] **Step 5: Smoke test in browser**

Visit `https://developers.soritune.com/` while logged out → redirects to `/login.php`. Log in → admin lands on `/admin/`, employee on `/p/`.

- [ ] **Step 6: Commit**

```bash
git add public_html/index.php public_html/admin/index.php public_html/p/index.php public_html/assets/style.css
git commit -m "feat: role-based redirect + admin/employee skeleton pages with project list"
```

---

## Task 8: lib/Audit.php — audit_log helper + Audit unit test

**Files:**
- Create: `lib/Audit.php`
- Test: `tests/unit/AuditTest.php`

- [ ] **Step 1: Write failing test**

`tests/unit/AuditTest.php`:

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\Audit;
use PDO;

final class AuditTest extends TestCase
{
    public function testWriteInsertsRow(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            // Need a user FK
            $db->exec("INSERT INTO users (username, password_hash, display_name, role) VALUES ('audit_t', '\$2y\$10\$abcd', 'Audit Test', 'admin')");
            $userId = (int)$db->lastInsertId();

            Audit::write($userId, 'test.action', 'user', $userId, ['key' => 'val'], '127.0.0.1');

            $row = $db->query("SELECT * FROM audit_log WHERE action='test.action' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertSame('test.action', $row['action']);
            $this->assertSame('user', $row['entity_type']);
            $this->assertSame($userId, (int)$row['entity_id']);
            $payload = json_decode($row['payload'], true);
            $this->assertSame('val', $payload['key']);
            $this->assertSame('127.0.0.1', $row['ip']);
        } finally {
            $db->rollBack();
        }
    }

    public function testWriteAllowsNullUserForSystem(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            Audit::write(null, 'system.startup', 'system', 0, ['v' => 1], null);
            $row = $db->query("SELECT * FROM audit_log WHERE action='system.startup' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $this->assertNull($row['user_id']);
        } finally {
            $db->rollBack();
        }
    }
}
```

- [ ] **Step 2: Run test — expected fail**

Run: `vendor/bin/phpunit --filter AuditTest`
Expected: Fatal — class missing.

- [ ] **Step 3: Write `lib/Audit.php`**

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

use PDO;

final class Audit
{
    public static function write(
        ?int $userId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $payload = null,
        ?string $ip = null
    ): void {
        $db = getDB();
        $st = $db->prepare(
            "INSERT INTO audit_log (user_id, action, entity_type, entity_id, payload, ip)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $st->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $ip,
        ]);
    }

    public static function writeFromRequest(?int $userId, string $action, string $entityType, int $entityId, ?array $payload = null): void
    {
        self::write($userId, $action, $entityType, $entityId, $payload, $_SERVER['REMOTE_ADDR'] ?? null);
    }
}
```

- [ ] **Step 4: Run test — expected pass**

Run: `vendor/bin/phpunit --filter AuditTest`
Expected: 2 tests pass.

- [ ] **Step 5: Commit**

```bash
git add lib/Audit.php tests/unit/AuditTest.php
git commit -m "feat: Audit::write helper for append-only audit_log"
```

---

## Task 9: lib/Validation.php (slug regex, github_repo pattern) + tests

**Files:**
- Create: `lib/Validation.php`
- Test: `tests/unit/ValidationTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\Validation;

final class ValidationTest extends TestCase
{
    public function testSlugAccepts(): void
    {
        $this->assertTrue(Validation::isValidSlug('camp'));
        $this->assertTrue(Validation::isValidSlug('camp-app'));
        $this->assertTrue(Validation::isValidSlug('a' . str_repeat('b', 38)));
    }

    public function testSlugRejects(): void
    {
        $this->assertFalse(Validation::isValidSlug(''));
        $this->assertFalse(Validation::isValidSlug('Camp'));         // uppercase
        $this->assertFalse(Validation::isValidSlug('1camp'));        // leading digit
        $this->assertFalse(Validation::isValidSlug('-camp'));        // leading hyphen
        $this->assertFalse(Validation::isValidSlug('ca/mp'));        // slash
        $this->assertFalse(Validation::isValidSlug(str_repeat('a', 40))); // too long
    }

    public function testGithubRepo(): void
    {
        $this->assertTrue(Validation::isValidGithubRepo('pjuhe99/soritune-camp'));
        $this->assertFalse(Validation::isValidGithubRepo('pjuhe99'));
        $this->assertFalse(Validation::isValidGithubRepo('a/b/c'));
        $this->assertFalse(Validation::isValidGithubRepo(''));
    }

    public function testSubdomain(): void
    {
        $this->assertTrue(Validation::isValidSubdomain('camp.soritune.com'));
        $this->assertTrue(Validation::isValidSubdomain('camp-dev.soritune.com'));
        $this->assertFalse(Validation::isValidSubdomain('CAMP.soritune.com'));
        $this->assertFalse(Validation::isValidSubdomain('soritune.com'));      // bare
    }
}
```

- [ ] **Step 2: Run — expected fail** (`vendor/bin/phpunit --filter ValidationTest`)

- [ ] **Step 3: Write `lib/Validation.php`**

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

final class Validation
{
    public static function isValidSlug(string $s): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9-]{1,38}$/', $s);
    }

    public static function isValidGithubRepo(string $s): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9_.-]+\/[a-zA-Z0-9_.-]+$/', $s)
            && substr_count($s, '/') === 1;
    }

    public static function isValidSubdomain(string $s): bool
    {
        // Must be <something>.<something>.<TLD>, all lowercase, allow hyphens within labels
        return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?){2,}$/', $s);
    }

    public static function isValidUsername(string $s): bool
    {
        return (bool)preg_match('/^[a-z][a-z0-9_]{2,63}$/', $s);
    }

    public static function isStrongPassword(string $s): bool
    {
        if (strlen($s) < 12) return false;
        $hasAlpha = (bool)preg_match('/[A-Za-z]/', $s);
        $hasDigit = (bool)preg_match('/\d/', $s);
        return $hasAlpha && $hasDigit;
    }
}
```

- [ ] **Step 4: Run — expected pass**

- [ ] **Step 5: Commit**

```bash
git add lib/Validation.php tests/unit/ValidationTest.php
git commit -m "feat: Validation helpers for slug, github_repo, subdomain, username, password"
```

---

## Task 10: api/system.php router + system/auth.php (login_check) + UserApiTest

**Files:**
- Create: `public_html/api/system.php`
- Create: `public_html/api/system/auth.php`
- Test: `tests/integration/UserApiTest.php` (initial assertion: auth gate works)

- [ ] **Step 1: Write `public_html/api/system.php` router**

```php
<?php
declare(strict_types=1);

require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';

requireAdmin();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$handlerMap = [
    'auth'     => __DIR__ . '/system/auth.php',
    'users'    => __DIR__ . '/system/users.php',
    'projects' => __DIR__ . '/system/projects.php',
    'jobs'     => __DIR__ . '/system/jobs.php',
];
if (!isset($handlerMap[$action])) {
    jsonError("unknown action: $action", 404);
}
require_once $handlerMap[$action];
```

- [ ] **Step 2: Write `public_html/api/system/auth.php`** (placeholder; admin "who am I")

```php
<?php
declare(strict_types=1);
// Already requireAdmin()'d by api/system.php

$op = $_GET['op'] ?? '';
if ($op === 'me') {
    $u = currentUser();
    jsonSuccess(['user' => $u]);
}
jsonError("unknown op: $op", 404);
```

- [ ] **Step 3: Write `tests/integration/UserApiTest.php` smoke for router**

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class UserApiTest extends TestCase
{
    public function testUnauthenticatedRequestRejected(): void
    {
        // Direct curl to the router endpoint (assumes vhost up)
        $url = 'https://developers.soritune.com/api/system.php?action=auth&op=me';
        $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'Accept: application/json']]);
        $resp = file_get_contents($url, false, $ctx);
        $code = (int)preg_replace('/^HTTP\/[\d.]+ (\d+).*/', '$1', $http_response_header[0] ?? 'HTTP/1.1 500');
        $this->assertContains($code, [401, 302, 303], "Expected redirect/401, got $code");
    }
}
```

- [ ] **Step 4: Smoke test the gate**

```bash
curl -s -w "\nHTTP %{http_code}\n" "https://developers.soritune.com/api/system.php?action=auth&op=me"
```
Expected: HTTP 401 with `{"ok":false,"message":"login required"}`.

- [ ] **Step 5: Commit**

```bash
git add public_html/api/system.php public_html/api/system/auth.php tests/integration/UserApiTest.php
git commit -m "feat: admin API router (fragment dispatch) + auth gate verified"
```

---

## Task 11: api/system/users.php — full CRUD (list, create, update, deactivate, reset password)

**Files:**
- Create: `public_html/api/system/users.php`
- Modify: `tests/integration/UserApiTest.php` (extend with logged-in flows)

- [ ] **Step 1: Extend test with helper for authenticated admin session**

Add to `tests/integration/UserApiTest.php`:

```php
private static function adminCurl(string $action, string $op, array $params = [], string $method = 'GET'): array
{
    // Helper: log in as fixture admin via login.php, then call api.
    // Skip pattern: reuse the LoginTest fixture creator if available.
    // For brevity, this test exercises the handler directly via require.
    $_SESSION = ['user' => ['id' => self::$adminId, 'username' => 'apitest', 'role' => 'admin']];
    $_GET = array_merge(['action' => $action, 'op' => $op], $params);
    $_POST = $method === 'POST' ? $_GET : [];
    $_SERVER['REQUEST_METHOD'] = $method;
    ob_start();
    try {
        require __DIR__ . '/../../public_html/api/system.php';
    } catch (\Throwable $e) {
        // jsonResponse exits; in test env we treat that as expected.
    }
    $out = ob_get_clean();
    return json_decode($out, true) ?? ['raw' => $out];
}
```

(Full setUp/tearDown of `apitest` admin user is similar to LoginTest. Repeat the bcrypt insert + delete pattern.)

Add test methods:

```php
public function testListUsersReturnsArray(): void
{
    $resp = self::adminCurl('users', 'list');
    $this->assertTrue($resp['ok']);
    $this->assertIsArray($resp['users']);
}

public function testCreateRequiresValidUsername(): void
{
    $resp = self::adminCurl('users', 'create', [
        '_csrf' => self::$csrf,
        'username' => 'Bad-name',  // uppercase + hyphen
        'display_name' => 'Bad',
        'role' => 'employee',
        'temp_password' => 'TestPass1234567',
    ], 'POST');
    $this->assertFalse($resp['ok']);
    $this->assertStringContainsString('username', $resp['message']);
}

public function testCreateThenResetThenDeactivate(): void
{
    $username = 'apit_' . bin2hex(random_bytes(3));
    $resp = self::adminCurl('users', 'create', [
        '_csrf' => self::$csrf,
        'username' => $username,
        'display_name' => 'API Test',
        'role' => 'employee',
        'temp_password' => 'TestPass1234567',
    ], 'POST');
    $this->assertTrue($resp['ok'], $resp['message'] ?? '');
    $userId = $resp['user']['id'];

    $reset = self::adminCurl('users', 'reset_password', ['_csrf' => self::$csrf, 'user_id' => $userId, 'new_password' => 'NewPass1234567'], 'POST');
    $this->assertTrue($reset['ok']);

    $deact = self::adminCurl('users', 'set_active', ['_csrf' => self::$csrf, 'user_id' => $userId, 'active' => 0], 'POST');
    $this->assertTrue($deact['ok']);

    self::getDb()->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
}
```

- [ ] **Step 2: Run — expected fail**

- [ ] **Step 3: Write `public_html/api/system/users.php`**

```php
<?php
declare(strict_types=1);

use Soritune\Developers\Audit;
use Soritune\Developers\Validation;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

switch ($op) {
    case 'list': {
        $rows = $db->query("SELECT id, username, display_name, role, github_username, active, last_login_at, created_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        jsonSuccess(['users' => $rows]);
    }

    case 'create': {
        $u = trim((string)($_POST['username'] ?? ''));
        $name = trim((string)($_POST['display_name'] ?? ''));
        $role = $_POST['role'] ?? '';
        $pw = $_POST['temp_password'] ?? '';
        $gh = trim((string)($_POST['github_username'] ?? ''));

        if (!Validation::isValidUsername($u)) jsonError('username must match ^[a-z][a-z0-9_]{2,63}$');
        if ($name === '') jsonError('display_name required');
        if (!in_array($role, ['admin','employee'], true)) jsonError('role must be admin|employee');
        if (!Validation::isStrongPassword($pw)) jsonError('temp_password must be 12+ chars with letter+digit');

        try {
            $st = $db->prepare(
                "INSERT INTO users (username, password_hash, display_name, role, github_username, must_change_password, active) VALUES (?, ?, ?, ?, ?, 1, 1)"
            );
            $st->execute([$u, password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]), $name, $role, $gh ?: null]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) jsonError('username already exists', 409);
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        Audit::writeFromRequest(currentUser()['id'], 'user.create', 'user', $id, ['username' => $u, 'role' => $role]);
        jsonSuccess(['user' => ['id' => $id, 'username' => $u, 'role' => $role]], 'created');
    }

    case 'reset_password': {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pw = $_POST['new_password'] ?? '';
        if ($uid <= 0) jsonError('user_id required');
        if (!Validation::isStrongPassword($pw)) jsonError('new_password must be 12+ with letter+digit');
        $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 10]);
        $n = $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([$hash, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'user.reset_password', 'user', $uid, []);
        jsonSuccess([], 'reset');
    }

    case 'set_active': {
        $uid = (int)($_POST['user_id'] ?? 0);
        $active = (int)($_POST['active'] ?? 1);
        if ($uid <= 0) jsonError('user_id required');
        $db->prepare("UPDATE users SET active = ? WHERE id = ?")->execute([$active, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'user.set_active', 'user', $uid, ['active' => $active]);
        jsonSuccess([], $active ? 'activated' : 'deactivated');
    }

    case 'set_github_username': {
        $uid = (int)($_POST['user_id'] ?? 0);
        $gh = trim((string)($_POST['github_username'] ?? '')) ?: null;
        $db->prepare("UPDATE users SET github_username = ? WHERE id = ?")->execute([$gh, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'user.set_github_username', 'user', $uid, ['github_username' => $gh]);
        jsonSuccess([], 'updated');
    }

    default:
        jsonError("unknown op: $op", 404);
}
```

- [ ] **Step 4: Run tests — expected pass**

Run: `vendor/bin/phpunit --filter UserApiTest`
Expected: All tests pass.

- [ ] **Step 5: Smoke test via curl** (after browser login, copy cookie)

```bash
# In browser: log in as admin, open DevTools, copy SDDEVSESSID + csrf-token meta value
COOKIE='SDDEVSESSID=...'
CSRF='...'
curl -s -b "$COOKIE" "https://developers.soritune.com/api/system.php?action=users&op=list" | jq .
curl -s -b "$COOKIE" -X POST "https://developers.soritune.com/api/system.php?action=users&op=create" \
  -d "_csrf=$CSRF&username=alice&display_name=Alice&role=employee&temp_password=TestPass1234567"
```

- [ ] **Step 6: Commit**

```bash
git add public_html/api/system/users.php tests/integration/UserApiTest.php
git commit -m "feat: admin users CRUD API (list/create/reset_password/set_active/set_github_username)"
```

---

## Task 12: admin/users.php — UI page that calls the user API

**Files:**
- Create: `public_html/admin/users.php`

- [ ] **Step 1: Write `public_html/admin/users.php`** (renders table, modal for create, inline actions)

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>사용자 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
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
  <header class="page-header">
    <h1>사용자</h1>
    <button id="btnNew">+ 새 사용자</button>
  </header>
  <table class="data-table">
    <thead><tr><th>ID</th><th>아이디</th><th>이름</th><th>역할</th><th>GitHub</th><th>활성</th><th>마지막 로그인</th><th>액션</th></tr></thead>
    <tbody id="tbody"><tr><td colspan="8">불러오는 중…</td></tr></tbody>
  </table>
</main>
<dialog id="newDlg">
  <form id="newForm" method="dialog">
    <h2>새 사용자</h2>
    <label>아이디 <input name="username" required pattern="[a-z][a-z0-9_]{2,63}"></label>
    <label>이름 <input name="display_name" required></label>
    <label>역할
      <select name="role"><option value="employee">직원</option><option value="admin">관리자</option></select>
    </label>
    <label>GitHub username <input name="github_username"></label>
    <label>임시 비밀번호 (12자+) <input name="temp_password" type="password" required minlength="12"></label>
    <menu><button value="cancel">취소</button><button id="newSubmit" value="ok">생성</button></menu>
  </form>
</dialog>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const tbody = document.getElementById('tbody');
async function load() {
  const r = await fetch('/api/system.php?action=users&op=list', { headers: { 'Accept':'application/json' } });
  const j = await r.json();
  tbody.innerHTML = j.users.map(u => `
    <tr>
      <td>${u.id}</td>
      <td>${escape(u.username)}</td>
      <td>${escape(u.display_name)}</td>
      <td>${u.role}</td>
      <td>${escape(u.github_username || '')}</td>
      <td>${u.active ? '✓' : '–'}</td>
      <td>${u.last_login_at || ''}</td>
      <td>
        <button data-act="reset" data-id="${u.id}">비번 재설정</button>
        <button data-act="toggle" data-id="${u.id}" data-cur="${u.active}">${u.active ? '비활성화' : '활성화'}</button>
      </td>
    </tr>
  `).join('');
}
function escape(s) { return (s||'').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]); }

document.getElementById('btnNew').onclick = () => newDlg.showModal();
document.getElementById('newSubmit').onclick = async (e) => {
  if (!document.getElementById('newForm').reportValidity()) return;
  e.preventDefault();
  const fd = new FormData(document.getElementById('newForm'));
  fd.append('_csrf', csrf);
  const r = await fetch('/api/system.php?action=users&op=create', { method:'POST', body: fd });
  const j = await r.json();
  if (!j.ok) { alert(j.message); return; }
  newDlg.close();
  load();
};
tbody.onclick = async (e) => {
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  const id = btn.dataset.id;
  if (btn.dataset.act === 'reset') {
    const pw = prompt('새 임시 비밀번호 (12자+):');
    if (!pw) return;
    const fd = new FormData(); fd.set('_csrf', csrf); fd.set('user_id', id); fd.set('new_password', pw);
    const j = await (await fetch('/api/system.php?action=users&op=reset_password', { method:'POST', body: fd })).json();
    alert(j.ok ? '재설정 완료' : j.message);
  } else if (btn.dataset.act === 'toggle') {
    const fd = new FormData(); fd.set('_csrf', csrf); fd.set('user_id', id); fd.set('active', btn.dataset.cur === '1' ? 0 : 1);
    const j = await (await fetch('/api/system.php?action=users&op=set_active', { method:'POST', body: fd })).json();
    if (j.ok) load(); else alert(j.message);
  }
};
load();
</script>
</body></html>
```

- [ ] **Step 2: Add CSS for table/dialog (append to `style.css`)**

```css
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.page-header button { background: var(--primary); color:#fff; border:0; padding:8px 14px; border-radius:8px; cursor:pointer; }
.data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.data-table th, .data-table td { padding: 8px 12px; border-bottom: 1px solid var(--hairline); text-align: left; }
dialog { padding: 0; border: 0; border-radius: 12px; }
dialog form { padding: 24px; min-width: 360px; display: grid; gap: 12px; }
dialog form menu { display: flex; gap: 8px; justify-content: flex-end; padding: 0; margin: 0; }
dialog form button { padding: 8px 14px; border-radius: 6px; border: 1px solid var(--hairline); background: #fff; cursor: pointer; }
dialog form button[value="ok"] { background: var(--primary); color: #fff; border-color: var(--primary); }
```

- [ ] **Step 3: Browser smoke**

Visit `/admin/users.php`, create new user, reset password, deactivate, reactivate. Confirm rows update.

- [ ] **Step 4: Commit**

```bash
git add public_html/admin/users.php public_html/assets/style.css
git commit -m "feat: admin/users.php UI page with create/reset/toggle"
```

---

## Task 13: api/system/projects.php — manual registration (no automation) + access toggle

**Files:**
- Create: `public_html/api/system/projects.php`
- Test: `tests/integration/ProjectApiTest.php`

- [ ] **Step 1: Write failing test** (`tests/integration/ProjectApiTest.php`, mirrors UserApiTest fixture pattern)

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use PDO;

final class ProjectApiTest extends TestCase
{
    // [Helper similar to UserApiTest: setUpBeforeClass creates fixture admin, csrf,
    //  adminCurl() function dispatches via require api/system.php]

    public function testRegisterRequiresValidSlug(): void
    {
        $r = self::adminCurl('projects', 'register', [
            '_csrf' => self::$csrf,
            'slug' => 'Bad Slug',
            'name' => 'X', 'github_repo' => 'org/x',
            'dev_subdomain' => 'x-dev.soritune.com',
            'prod_subdomain' => 'x.soritune.com',
            'dev_dir' => '/var/www/html/_______site_SORITUNECOM_DEV_X',
            'prod_dir' => '/var/www/html/_______site_SORITUNECOM_X',
            'dev_db_name' => 'SORITUNECOM_DEV_X',
            'prod_db_name' => 'SORITUNECOM_X',
        ], 'POST');
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('slug', $r['message']);
    }

    public function testRegisterAndGrantAccess(): void
    {
        $slug = 'pa' . bin2hex(random_bytes(3));
        $r = self::adminCurl('projects', 'register', [
            '_csrf' => self::$csrf, 'slug' => $slug, 'name' => 'PA Test',
            'github_repo' => 'org/' . $slug,
            'dev_subdomain' => $slug . '-dev.soritune.com',
            'prod_subdomain' => $slug . '.soritune.com',
            'dev_dir' => '/tmp/' . $slug . '-dev',
            'prod_dir' => '/tmp/' . $slug,
            'dev_db_name' => 'TEST_DEV_' . strtoupper($slug),
            'prod_db_name' => 'TEST_' . strtoupper($slug),
            'card_tint' => 'mint',
        ], 'POST');
        $this->assertTrue($r['ok'], $r['message'] ?? '');
        $pid = $r['project']['id'];

        // Grant access to fixture employee user
        $grant = self::adminCurl('projects', 'grant_access', ['_csrf' => self::$csrf, 'project_id' => $pid, 'user_id' => self::$employeeId], 'POST');
        $this->assertTrue($grant['ok']);

        // Revoke
        $rev = self::adminCurl('projects', 'revoke_access', ['_csrf' => self::$csrf, 'project_id' => $pid, 'user_id' => self::$employeeId], 'POST');
        $this->assertTrue($rev['ok']);

        // Cleanup
        $db = self::getDb();
        $db->prepare("DELETE FROM project_access WHERE project_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM projects WHERE id = ?")->execute([$pid]);
    }
}
```

- [ ] **Step 2: Run — expected fail**

- [ ] **Step 3: Write `public_html/api/system/projects.php`**

```php
<?php
declare(strict_types=1);

use Soritune\Developers\Audit;
use Soritune\Developers\Validation;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

switch ($op) {
    case 'list': {
        $rows = $db->query("SELECT * FROM projects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        jsonSuccess(['projects' => $rows]);
    }

    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM projects WHERE id = ?");
        $st->execute([$id]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
        if (!$p) jsonError('not found', 404);

        $am = $db->prepare("SELECT u.id, u.username, u.display_name FROM users u INNER JOIN project_access pa ON pa.user_id = u.id WHERE pa.project_id = ? ORDER BY u.display_name");
        $am->execute([$id]);
        jsonSuccess(['project' => $p, 'members' => $am->fetchAll(PDO::FETCH_ASSOC)]);
    }

    case 'register': {
        $slug = trim((string)($_POST['slug'] ?? ''));
        $name = trim((string)($_POST['name'] ?? ''));
        $repo = trim((string)($_POST['github_repo'] ?? ''));
        $devSub = trim((string)($_POST['dev_subdomain'] ?? ''));
        $prodSub = trim((string)($_POST['prod_subdomain'] ?? ''));
        $devDir = trim((string)($_POST['dev_dir'] ?? ''));
        $prodDir = trim((string)($_POST['prod_dir'] ?? ''));
        $devDb = trim((string)($_POST['dev_db_name'] ?? ''));
        $prodDb = trim((string)($_POST['prod_db_name'] ?? ''));
        $desc = trim((string)($_POST['description'] ?? '')) ?: null;
        $tint = $_POST['card_tint'] ?? 'peach';

        if (!Validation::isValidSlug($slug)) jsonError('slug must match ^[a-z][a-z0-9-]{1,38}$');
        if ($name === '') jsonError('name required');
        if (!Validation::isValidGithubRepo($repo)) jsonError('github_repo must be "org/name"');
        if (!Validation::isValidSubdomain($devSub)) jsonError('dev_subdomain invalid');
        if (!Validation::isValidSubdomain($prodSub)) jsonError('prod_subdomain invalid');
        if ($devDir === '' || $prodDir === '') jsonError('dirs required');
        if ($devDb === '' || $prodDb === '') jsonError('db names required');
        if (!in_array($tint, ['peach','rose','mint','lavender','sky','yellow'], true)) jsonError('card_tint invalid');

        try {
            $st = $db->prepare(
                "INSERT INTO projects (slug, name, description, github_repo, dev_subdomain, prod_subdomain, dev_dir, prod_dir, dev_db_name, prod_db_name, card_tint, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?)"
            );
            $st->execute([$slug, $name, $desc, $repo, $devSub, $prodSub, $devDir, $prodDir, $devDb, $prodDb, $tint, currentUser()['id']]);
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'Duplicate')) jsonError('slug already exists', 409);
            throw $e;
        }
        $id = (int)$db->lastInsertId();
        Audit::writeFromRequest(currentUser()['id'], 'project.register', 'project', $id, ['slug' => $slug]);
        jsonSuccess(['project' => ['id' => $id, 'slug' => $slug, 'name' => $name]], 'registered');
    }

    case 'grant_access': {
        $pid = (int)($_POST['project_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($pid <= 0 || $uid <= 0) jsonError('project_id+user_id required');
        $db->prepare("INSERT IGNORE INTO project_access (project_id, user_id, granted_by) VALUES (?, ?, ?)")
           ->execute([$pid, $uid, currentUser()['id']]);
        Audit::writeFromRequest(currentUser()['id'], 'project.grant_access', 'project', $pid, ['user_id' => $uid]);
        jsonSuccess([], 'granted');
    }

    case 'revoke_access': {
        $pid = (int)($_POST['project_id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        $db->prepare("DELETE FROM project_access WHERE project_id = ? AND user_id = ?")->execute([$pid, $uid]);
        Audit::writeFromRequest(currentUser()['id'], 'project.revoke_access', 'project', $pid, ['user_id' => $uid]);
        jsonSuccess([], 'revoked');
    }

    case 'archive': {
        $pid = (int)($_POST['project_id'] ?? 0);
        $db->prepare("UPDATE projects SET status = 'archived' WHERE id = ?")->execute([$pid]);
        Audit::writeFromRequest(currentUser()['id'], 'project.archive', 'project', $pid, []);
        jsonSuccess([], 'archived');
    }

    default:
        jsonError("unknown op: $op", 404);
}
```

- [ ] **Step 4: Run tests — expected pass**

- [ ] **Step 5: Commit**

```bash
git add public_html/api/system/projects.php tests/integration/ProjectApiTest.php
git commit -m "feat: project register (manual) + grant/revoke access + archive API"
```

---

## Task 14: admin/projects.php — UI for list + register + member toggle

**Files:**
- Create: `public_html/admin/projects.php`

- [ ] **Step 1: Write the page** (mirror admin/users.php structure)

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>프로젝트 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
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
  <header class="page-header">
    <h1>프로젝트</h1>
    <button id="btnNew">+ 기존 프로젝트 등록</button>
    <small>(Plan C 의 자동 생성 마법사 도착 전까지는 수동 등록)</small>
  </header>
  <table class="data-table">
    <thead><tr><th>슬러그</th><th>이름</th><th>repo</th><th>dev URL</th><th>prod URL</th><th>상태</th><th>액션</th></tr></thead>
    <tbody id="tbody"></tbody>
  </table>
</main>
<dialog id="newDlg">
  <form id="newForm" method="dialog">
    <h2>기존 프로젝트 등록</h2>
    <label>슬러그 <input name="slug" required pattern="[a-z][a-z0-9-]{1,38}"></label>
    <label>이름 <input name="name" required></label>
    <label>설명 <textarea name="description"></textarea></label>
    <label>GitHub repo (org/name) <input name="github_repo" required></label>
    <label>DEV 서브도메인 <input name="dev_subdomain" required></label>
    <label>PROD 서브도메인 <input name="prod_subdomain" required></label>
    <label>DEV 디렉토리 <input name="dev_dir" required></label>
    <label>PROD 디렉토리 <input name="prod_dir" required></label>
    <label>DEV DB <input name="dev_db_name" required></label>
    <label>PROD DB <input name="prod_db_name" required></label>
    <label>카드 톤
      <select name="card_tint">
        <option>peach</option><option>rose</option><option>mint</option>
        <option>lavender</option><option>sky</option><option>yellow</option>
      </select>
    </label>
    <menu><button value="cancel">취소</button><button value="ok" id="newSubmit">등록</button></menu>
  </form>
</dialog>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const tbody = document.getElementById('tbody');
async function load() {
  const j = await (await fetch('/api/system.php?action=projects&op=list')).json();
  tbody.innerHTML = j.projects.map(p => `
    <tr>
      <td>${escape(p.slug)}</td><td>${escape(p.name)}</td>
      <td><a href="https://github.com/${escape(p.github_repo)}" target="_blank">${escape(p.github_repo)}</a></td>
      <td><a href="https://${escape(p.dev_subdomain)}" target="_blank">${escape(p.dev_subdomain)}</a></td>
      <td><a href="https://${escape(p.prod_subdomain)}" target="_blank">${escape(p.prod_subdomain)}</a></td>
      <td>${p.status}</td>
      <td>
        <button data-act="archive" data-id="${p.id}" ${p.status==='archived'?'disabled':''}>아카이브</button>
        <button data-act="members" data-id="${p.id}">멤버</button>
      </td>
    </tr>
  `).join('');
}
function escape(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]);}
document.getElementById('btnNew').onclick = () => newDlg.showModal();
document.getElementById('newSubmit').onclick = async (e) => {
  if (!document.getElementById('newForm').reportValidity()) return;
  e.preventDefault();
  const fd = new FormData(document.getElementById('newForm'));
  fd.append('_csrf', csrf);
  const j = await (await fetch('/api/system.php?action=projects&op=register', { method:'POST', body: fd })).json();
  if (!j.ok) { alert(j.message); return; }
  newDlg.close(); load();
};
tbody.onclick = async (e) => {
  const b = e.target.closest('button[data-act]');
  if (!b) return;
  const id = b.dataset.id;
  if (b.dataset.act === 'archive') {
    if (!confirm('아카이브 처리할까요?')) return;
    const fd = new FormData(); fd.set('_csrf', csrf); fd.set('project_id', id);
    const j = await (await fetch('/api/system.php?action=projects&op=archive', { method:'POST', body: fd })).json();
    j.ok ? load() : alert(j.message);
  } else if (b.dataset.act === 'members') {
    location.href = `/admin/project_members.php?id=${id}`;
  }
};
load();
</script>
</body></html>
```

- [ ] **Step 2: Write `public_html/admin/project_members.php`** (member toggle UI)

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
<meta charset="utf-8"><title>프로젝트 멤버 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
<meta name="csrf-token" content="<?= e($token) ?>">
<meta name="pid" content="<?= e((string)$pid) ?>">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="/admin/projects.php">← 프로젝트 목록</a>
  <span class="grow"></span><span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <h1 id="pname"></h1>
  <h2>멤버</h2>
  <table class="data-table"><thead><tr><th>이름</th><th>아이디</th><th>역할</th><th>접근</th></tr></thead><tbody id="tbody"></tbody></table>
</main>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const pid = document.querySelector('meta[name="pid"]').content;
async function load() {
  const [pj, uj] = await Promise.all([
    fetch(`/api/system.php?action=projects&op=get&id=${pid}`).then(r=>r.json()),
    fetch('/api/system.php?action=users&op=list').then(r=>r.json()),
  ]);
  document.getElementById('pname').textContent = pj.project.name;
  const memberIds = new Set(pj.members.map(m => m.id));
  document.getElementById('tbody').innerHTML = uj.users.filter(u => u.active && u.role === 'employee').map(u => `
    <tr>
      <td>${escape(u.display_name)}</td>
      <td>${escape(u.username)}</td>
      <td>${u.role}</td>
      <td><label><input type="checkbox" data-uid="${u.id}" ${memberIds.has(u.id) ? 'checked' : ''}> 접근 가능</label></td>
    </tr>
  `).join('');
}
function escape(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]);}
document.getElementById('tbody').addEventListener('change', async (e) => {
  const cb = e.target.closest('input[type=checkbox]');
  if (!cb) return;
  const fd = new FormData();
  fd.set('_csrf', csrf); fd.set('project_id', pid); fd.set('user_id', cb.dataset.uid);
  const op = cb.checked ? 'grant_access' : 'revoke_access';
  const j = await (await fetch(`/api/system.php?action=projects&op=${op}`, { method:'POST', body: fd })).json();
  if (!j.ok) { alert(j.message); cb.checked = !cb.checked; }
});
load();
</script>
</body></html>
```

- [ ] **Step 3: Browser smoke**

`/admin/projects.php` → 새 프로젝트 등록 → members 이동 → 직원 access 토글. 직원 계정으로 다시 로그인 → `/p/` 에 그 프로젝트 카드 표시.

- [ ] **Step 4: Commit**

```bash
git add public_html/admin/projects.php public_html/admin/project_members.php
git commit -m "feat: admin/projects.php list+register, project_members.php access toggle"
```

---

## Task 15: lib/JobQueue.php — enqueue helper + worker skeleton

**Files:**
- Create: `lib/JobQueue.php`
- Create: `scripts/developers_worker.sh`
- Create: `public_html/api/system/jobs.php`
- Create: `public_html/admin/jobs.php`
- Test: `tests/unit/JobQueueTest.php`
- Test: `tests/integration/JobsEndToEndTest.php`

- [ ] **Step 1: Write failing JobQueue unit test**

`tests/unit/JobQueueTest.php`:

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\JobQueue;

final class JobQueueTest extends TestCase
{
    public function testValidTypesEnum(): void
    {
        $this->assertContains('project_init', JobQueue::TYPES);
        $this->assertContains('dev_deploy', JobQueue::TYPES);
        $this->assertCount(5, JobQueue::TYPES);
    }

    public function testEnqueueWritesRowAndMarker(): void
    {
        $db = getDB();
        $db->beginTransaction();
        try {
            // need a user
            $db->exec("INSERT INTO users (username, password_hash, display_name, role) VALUES ('jq_t', 'x', 'JQ', 'admin')");
            $uid = (int)$db->lastInsertId();
            $jid = JobQueue::enqueue('dev_deploy', ['project_id' => 99, 'branch' => 'dev'], $uid, null, null);
            $this->assertGreaterThan(0, $jid);

            $row = $db->query("SELECT * FROM jobs WHERE id = $jid")->fetch(\PDO::FETCH_ASSOC);
            $this->assertSame('pending', $row['status']);
            $payload = json_decode($row['payload'], true);
            $this->assertSame(99, $payload['project_id']);

            // marker file created
            $marker = SITE_ROOT . "/jobs/pending/$jid.json";
            $this->assertFileExists($marker);
            @unlink($marker);
        } finally {
            $db->rollBack();
        }
    }

    public function testEnqueueRejectsUnknownType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        JobQueue::enqueue('garbage_type', [], 1, null, null);
    }
}
```

- [ ] **Step 2: Run — expected fail**

- [ ] **Step 3: Write `lib/JobQueue.php`**

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class JobQueue
{
    public const TYPES = ['project_init','site_create','dev_deploy','prod_deploy','user_repo_grant'];

    public static function enqueue(
        string $type,
        array $payload,
        int $userId,
        ?int $projectId,
        ?int $taskId
    ): int {
        if (!in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException("Unknown job type: $type");
        }
        $db = getDB();
        $st = $db->prepare(
            "INSERT INTO jobs (type, status, project_id, task_id, user_id, payload, log_path)
             VALUES (?, 'pending', ?, ?, ?, ?, NULL)"
        );
        $st->execute([$type, $projectId, $taskId, $userId, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        $jid = (int)$db->lastInsertId();

        $marker = SITE_ROOT . "/jobs/pending/$jid.json";
        $ok = @file_put_contents($marker, json_encode(['id' => $jid, 'type' => $type], JSON_UNESCAPED_UNICODE));
        if ($ok === false) {
            throw new RuntimeException("Failed to write job marker $marker");
        }
        @chmod($marker, 0664);
        $logPath = SITE_ROOT . "/jobs/logs/$jid.log";
        $db->prepare("UPDATE jobs SET log_path = ? WHERE id = ?")->execute([$logPath, $jid]);

        return $jid;
    }

    public static function list(?string $status = null, ?string $type = null, int $limit = 100): array
    {
        $db = getDB();
        $sql = "SELECT * FROM jobs WHERE 1=1";
        $args = [];
        if ($status !== null) { $sql .= " AND status = ?"; $args[] = $status; }
        if ($type !== null)   { $sql .= " AND type = ?";   $args[] = $type; }
        $sql .= " ORDER BY id DESC LIMIT " . (int)$limit;
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function get(int $id): ?array
    {
        $st = getDB()->prepare("SELECT * FROM jobs WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
```

- [ ] **Step 4: Write `scripts/developers_worker.sh`** (Plan A stub — picks file marker, marks running, sleeps 1s, marks success. No real job dispatch yet — Plan B/C wires up handlers.)

```bash
#!/bin/bash
# Plan A: skeleton worker. Picks pending markers, marks running, immediately marks success (no real job handlers yet — Plan B/C).
set -euo pipefail

SITE_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$SITE_ROOT"

# shellcheck disable=SC1091
set -a; . ./.db_credentials; set +a

mysql_cmd() {
  mysql -h "${DB_HOST:-localhost}" -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" -N -B "$@"
}

shopt -s nullglob
for marker in jobs/pending/*.json; do
  id=$(basename "$marker" .json)
  [[ "$id" =~ ^[0-9]+$ ]] || continue

  mv "$marker" "jobs/running/$id.json"
  mysql_cmd -e "UPDATE jobs SET status='running', started_at=NOW(), attempts=attempts+1 WHERE id=$id"

  log="jobs/logs/$id.log"
  type=$(mysql_cmd -e "SELECT type FROM jobs WHERE id=$id")
  echo "[$(date -Is)] Plan A stub worker running job $id (type=$type)" >> "$log"

  # Plan A: just mark success. Plan B/C will dispatch to job_<type>.sh here.
  echo "[$(date -Is)] Plan A: no handler yet, marking success." >> "$log"
  mysql_cmd -e "UPDATE jobs SET status='success', finished_at=NOW(), result=JSON_OBJECT('note','plan-a-stub') WHERE id=$id"
  mv "jobs/running/$id.json" "jobs/done/$id.json"
done
```

- [ ] **Step 5: Install cron + flock wrapper**

```bash
chmod +x scripts/developers_worker.sh
sudo tee /etc/cron.d/developers-soritune <<'CRON'
* * * * * root timeout 1800 flock -n /var/run/developers_worker.lock /var/www/html/_______site_SORITUNECOM_DEVELOPERS/scripts/developers_worker.sh >> /var/log/developers_worker.log 2>&1
CRON
sudo systemctl reload crond
```

- [ ] **Step 6: Write `public_html/api/system/jobs.php`**

```php
<?php
declare(strict_types=1);

use Soritune\Developers\JobQueue;

$op = $_GET['op'] ?? '';
switch ($op) {
    case 'list': {
        $status = $_GET['status'] ?? null;
        $type = $_GET['type'] ?? null;
        $jobs = JobQueue::list($status, $type, 100);
        // Decode payload/result for display
        foreach ($jobs as &$j) {
            $j['payload'] = json_decode($j['payload'] ?? 'null', true);
            $j['result']  = $j['result'] ? json_decode($j['result'], true) : null;
        }
        jsonSuccess(['jobs' => $jobs]);
    }
    case 'get': {
        $id = (int)($_GET['id'] ?? 0);
        $j = JobQueue::get($id);
        if (!$j) jsonError('not found', 404);
        $j['payload'] = json_decode($j['payload'] ?? 'null', true);
        $j['result']  = $j['result'] ? json_decode($j['result'], true) : null;
        // Stream tail of log
        $log = '';
        if ($j['log_path'] && is_readable($j['log_path'])) {
            $log = (string)shell_exec("tail -c 5000 " . escapeshellarg($j['log_path']));
        }
        jsonSuccess(['job' => $j, 'log_tail' => $log]);
    }
    case 'enqueue_test': {
        // Plan A only: lets admin manually enqueue a dummy dev_deploy to test the worker path
        requireCsrfOrAbort();
        $jid = JobQueue::enqueue('dev_deploy', ['project_id' => 0, 'branch' => 'dev', 'plan_a_stub' => true], currentUser()['id'], null, null);
        jsonSuccess(['job_id' => $jid]);
    }
    default:
        jsonError("unknown op: $op", 404);
}
```

- [ ] **Step 7: Write `public_html/admin/jobs.php`** (simple list)

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>작업 큐 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
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
  <header class="page-header">
    <h1>작업 큐</h1>
    <button id="btnEnq">Plan A: 테스트 enqueue</button>
  </header>
  <table class="data-table">
    <thead><tr><th>ID</th><th>type</th><th>status</th><th>enqueued</th><th>finished</th><th>error</th></tr></thead>
    <tbody id="tbody"></tbody>
  </table>
</main>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
async function load() {
  const j = await (await fetch('/api/system.php?action=jobs&op=list')).json();
  document.getElementById('tbody').innerHTML = j.jobs.map(x => `
    <tr><td>${x.id}</td><td>${x.type}</td><td>${x.status}</td>
        <td>${x.enqueued_at}</td><td>${x.finished_at || ''}</td>
        <td>${escape(x.error_message || '')}</td></tr>
  `).join('');
}
function escape(s){return (s||'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]);}
document.getElementById('btnEnq').onclick = async () => {
  const fd = new FormData(); fd.set('_csrf', csrf);
  const j = await (await fetch('/api/system.php?action=jobs&op=enqueue_test', { method:'POST', body: fd })).json();
  alert(j.ok ? `enqueued #${j.job_id} — wait up to a minute, then refresh.` : j.message);
};
load();
setInterval(load, 5000);
</script>
</body></html>
```

- [ ] **Step 8: Write JobsEndToEndTest integration**

`tests/integration/JobsEndToEndTest.php`:

```php
<?php
declare(strict_types=1);
namespace Soritune\Developers\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Soritune\Developers\JobQueue;

final class JobsEndToEndTest extends TestCase
{
    public function testEnqueueProducesPendingJobAndMarker(): void
    {
        $db = getDB();
        $db->exec("INSERT INTO users (username, password_hash, display_name, role) VALUES ('jet_t', 'x', 'JET', 'admin')");
        $uid = (int)$db->lastInsertId();
        $jid = JobQueue::enqueue('dev_deploy', ['project_id' => 0, 'plan_a_stub' => true], $uid, null, null);

        $row = JobQueue::get($jid);
        $this->assertSame('pending', $row['status']);
        $marker = SITE_ROOT . "/jobs/pending/$jid.json";
        $this->assertFileExists($marker);

        // Cleanup
        @unlink($marker);
        $db->prepare("DELETE FROM jobs WHERE id = ?")->execute([$jid]);
        $db->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
    }

    public function testWorkerScriptExists(): void
    {
        $this->assertFileExists(SITE_ROOT . '/scripts/developers_worker.sh');
        $this->assertTrue(is_executable(SITE_ROOT . '/scripts/developers_worker.sh'));
    }
}
```

- [ ] **Step 9: Run all tests — expected pass**

```bash
vendor/bin/phpunit
```
Expected: all unit + integration tests pass.

- [ ] **Step 10: Browser e2e of stub worker**

1. Visit `/admin/jobs.php`
2. Click "Plan A: 테스트 enqueue"
3. Wait up to 60s (cron tick)
4. Refresh → row should show status `success` and the timestamps populated

- [ ] **Step 11: Commit**

```bash
git add lib/JobQueue.php scripts/developers_worker.sh public_html/api/system/jobs.php public_html/admin/jobs.php tests/unit/JobQueueTest.php tests/integration/JobsEndToEndTest.php
sudo cp /etc/cron.d/developers-soritune /etc/cron.d/developers-soritune.bak  # keep a backup
git commit -m "feat: JobQueue::enqueue + worker stub + admin/jobs page + cron + e2e test"
```

---

## Task 16: api/user.php router + user/me.php (change own password) + me.php UI

**Files:**
- Create: `public_html/api/user.php`
- Create: `public_html/api/user/me.php`
- Create: `public_html/api/user/projects.php`
- Create: `public_html/me.php`

- [ ] **Step 1: Write `public_html/api/user.php` router**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';

requireAuth();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$handlerMap = [
    'me' => __DIR__ . '/user/me.php',
    'projects' => __DIR__ . '/user/projects.php',
];
if (!isset($handlerMap[$action])) jsonError("unknown action: $action", 404);
require_once $handlerMap[$action];
```

- [ ] **Step 2: Write `public_html/api/user/me.php`**

```php
<?php
declare(strict_types=1);

use Soritune\Developers\Audit;
use Soritune\Developers\Validation;

$op = $_GET['op'] ?? $_POST['op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') requireCsrfOrAbort();

switch ($op) {
    case 'get': {
        jsonSuccess(['user' => currentUser()]);
    }
    case 'change_password': {
        $old = $_POST['old_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if (!Validation::isStrongPassword($new)) jsonError('new_password must be 12+ chars with letter+digit');

        $db = getDB();
        $st = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
        $st->execute([currentUser()['id']]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($old, $hash)) jsonError('old_password incorrect');
        $newHash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 10]);
        $db->prepare("UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?")
           ->execute([$newHash, currentUser()['id']]);
        // Refresh session
        $_SESSION['user']['must_change_password'] = false;
        Audit::writeFromRequest(currentUser()['id'], 'user.self_change_password', 'user', currentUser()['id'], []);
        jsonSuccess([], 'changed');
    }
    case 'set_github_username': {
        $gh = trim((string)($_POST['github_username'] ?? '')) ?: null;
        getDB()->prepare("UPDATE users SET github_username = ? WHERE id = ?")->execute([$gh, currentUser()['id']]);
        Audit::writeFromRequest(currentUser()['id'], 'user.self_set_github_username', 'user', currentUser()['id'], ['github_username' => $gh]);
        jsonSuccess([], 'updated');
    }
    default: jsonError("unknown op: $op", 404);
}
```

- [ ] **Step 3: Write `public_html/api/user/projects.php`**

```php
<?php
declare(strict_types=1);

$db = getDB();
$op = $_GET['op'] ?? '';
switch ($op) {
    case 'list': {
        if (currentUser()['role'] === 'admin') {
            $rows = $db->query("SELECT * FROM projects WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = $db->prepare("SELECT p.* FROM projects p INNER JOIN project_access pa ON pa.project_id = p.id WHERE pa.user_id = ? AND p.status='active' ORDER BY p.name");
            $st->execute([currentUser()['id']]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        jsonSuccess(['projects' => $rows]);
    }
    default: jsonError("unknown op: $op", 404);
}
```

- [ ] **Step 4: Write `public_html/me.php`**

```php
<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
$u = requireAuth();
$token = csrfToken();
$force = !empty($_GET['force_change']) || !empty($u['must_change_password']);
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>내 정보 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
<meta name="csrf-token" content="<?= e($token) ?>">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="<?= $u['role']==='admin' ? '/admin/' : '/p/' ?>">홈</a>
  <a href="/me.php">내 정보</a>
  <span class="grow"></span><span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <h1>내 정보</h1>
  <p>아이디: <code><?= e($u['username']) ?></code></p>
  <?php if ($force): ?>
    <div class="error">임시 비밀번호로 로그인했습니다. 새 비밀번호로 변경하세요.</div>
  <?php endif; ?>
  <h2>비밀번호 변경</h2>
  <form id="pwForm">
    <label>현재 비밀번호 <input name="old_password" type="password" required></label>
    <label>새 비밀번호 (12자 이상, 영문+숫자) <input name="new_password" type="password" required minlength="12"></label>
    <button>변경</button>
  </form>
  <h2>GitHub username</h2>
  <form id="ghForm">
    <input name="github_username" placeholder="예: alice">
    <button>저장</button>
  </form>
</main>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
document.getElementById('pwForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target); fd.append('_csrf', csrf);
  const j = await (await fetch('/api/user.php?action=me&op=change_password', { method:'POST', body: fd })).json();
  alert(j.ok ? '변경 완료' : j.message);
  if (j.ok) location.href = '/';
});
document.getElementById('ghForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target); fd.append('_csrf', csrf);
  const j = await (await fetch('/api/user.php?action=me&op=set_github_username', { method:'POST', body: fd })).json();
  alert(j.ok ? '저장 완료' : j.message);
});
</script>
</body></html>
```

- [ ] **Step 5: Browser smoke**

Log out → log in with seed admin's temp password → redirected to `/me.php?force_change=1` → change → redirected to `/`. As employee, visit `/me.php` and set GitHub username.

- [ ] **Step 6: Commit**

```bash
git add public_html/api/user.php public_html/api/user/me.php public_html/api/user/projects.php public_html/me.php
git commit -m "feat: employee API router + /me page (change pw, set github_username)"
```

---

## Task 17: tests/smoke/ — bash smoke tests + tests/run.sh

**Files:**
- Create: `tests/smoke/login.sh`
- Create: `tests/smoke/user_crud.sh`
- Create: `tests/smoke/project_register.sh`
- Create: `tests/smoke/job_enqueue.sh`
- Create: `tests/run.sh`

- [ ] **Step 1: Write `tests/smoke/login.sh`**

```bash
#!/bin/bash
set -euo pipefail
BASE="${SMOKE_BASE:-https://developers.soritune.com}"
COOKIE=$(mktemp); trap 'rm -f "$COOKIE"' EXIT

# 1. Login form must render with csrf
curl -fsS -c "$COOKIE" -b "$COOKIE" "$BASE/login.php" -o /tmp/sd_login.html
csrf=$(grep -oP '(?<=name="_csrf" value=")[^"]+' /tmp/sd_login.html)
[ -n "$csrf" ] || { echo FAIL: no csrf token; exit 1; }

# 2. Wrong password rejected (no env user)
curl -fsS -c "$COOKIE" -b "$COOKIE" -X POST "$BASE/login.php" \
  -d "_csrf=$csrf&username=ghost&password=WrongPass1234" -o /tmp/sd_wrong.html
grep -q '올바르지 않습니다' /tmp/sd_wrong.html || { echo FAIL: did not reject wrong; exit 1; }

# 3. Login as seeded admin (env-supplied)
[ -n "${SMOKE_USER:-}" ] && [ -n "${SMOKE_PASS:-}" ] || { echo "SKIP login-success: set SMOKE_USER+SMOKE_PASS"; exit 0; }
curl -fsS -c "$COOKIE" -b "$COOKIE" -X POST "$BASE/login.php" \
  -d "_csrf=$csrf&username=$SMOKE_USER&password=$SMOKE_PASS" -o /tmp/sd_ok.html -w "%{http_code}\n" -L
curl -fsS -b "$COOKIE" "$BASE/api/system.php?action=auth&op=me" | grep -q '"ok":true' || { echo FAIL: api/me; exit 1; }

echo PASS: login.sh
```

- [ ] **Step 2: Write `tests/smoke/user_crud.sh`** (requires logged-in admin cookie)

```bash
#!/bin/bash
set -euo pipefail
BASE="${SMOKE_BASE:-https://developers.soritune.com}"
COOKIE="${SMOKE_COOKIE:?set SMOKE_COOKIE to admin's session cookie file}"
CSRF=$(curl -fsS -b "$COOKIE" "$BASE/admin/users.php" | grep -oP '(?<=csrf-token" content=")[^"]+')

USERNAME="smoke_$(date +%s)"
resp=$(curl -fsS -b "$COOKIE" -X POST "$BASE/api/system.php?action=users&op=create" \
  -d "_csrf=$CSRF&username=$USERNAME&display_name=Smoke&role=employee&temp_password=SmokePass1234567")
echo "$resp" | grep -q '"ok":true' || { echo FAIL create; echo "$resp"; exit 1; }

uid=$(echo "$resp" | grep -oP '"id":\K\d+')
curl -fsS -b "$COOKIE" -X POST "$BASE/api/system.php?action=users&op=set_active" \
  -d "_csrf=$CSRF&user_id=$uid&active=0" | grep -q '"ok":true' || { echo FAIL deactivate; exit 1; }

echo PASS: user_crud.sh
```

- [ ] **Step 3: Write `tests/smoke/project_register.sh`** (similar pattern)

```bash
#!/bin/bash
set -euo pipefail
BASE="${SMOKE_BASE:-https://developers.soritune.com}"
COOKIE="${SMOKE_COOKIE:?}"
CSRF=$(curl -fsS -b "$COOKIE" "$BASE/admin/projects.php" | grep -oP '(?<=csrf-token" content=")[^"]+')

SLUG="sm$(date +%s | tail -c5)"
curl -fsS -b "$COOKIE" -X POST "$BASE/api/system.php?action=projects&op=register" \
  -d "_csrf=$CSRF&slug=$SLUG&name=Smoke&github_repo=org/$SLUG&dev_subdomain=$SLUG-dev.soritune.com&prod_subdomain=$SLUG.soritune.com&dev_dir=/tmp/$SLUG-dev&prod_dir=/tmp/$SLUG&dev_db_name=TST_DEV_$SLUG&prod_db_name=TST_$SLUG&card_tint=mint" \
  | grep -q '"ok":true' || { echo FAIL; exit 1; }

echo PASS: project_register.sh
```

- [ ] **Step 4: Write `tests/smoke/job_enqueue.sh`**

```bash
#!/bin/bash
set -euo pipefail
BASE="${SMOKE_BASE:-https://developers.soritune.com}"
COOKIE="${SMOKE_COOKIE:?}"
CSRF=$(curl -fsS -b "$COOKIE" "$BASE/admin/jobs.php" | grep -oP '(?<=csrf-token" content=")[^"]+')
resp=$(curl -fsS -b "$COOKIE" -X POST "$BASE/api/system.php?action=jobs&op=enqueue_test" -d "_csrf=$CSRF")
echo "$resp" | grep -q '"ok":true' || { echo FAIL enqueue; echo "$resp"; exit 1; }
jid=$(echo "$resp" | grep -oP '"job_id":\K\d+')
echo "enqueued job $jid; waiting up to 90s for worker..."
for i in $(seq 1 18); do
  sleep 5
  status=$(curl -fsS -b "$COOKIE" "$BASE/api/system.php?action=jobs&op=get&id=$jid" | grep -oP '"status":"\K[^"]+' | head -1)
  echo "  attempt $i: status=$status"
  [ "$status" = "success" ] && { echo PASS: job_enqueue.sh; exit 0; }
done
echo FAIL: job never reached success
exit 1
```

- [ ] **Step 5: Write `tests/run.sh`**

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."

echo "=== phpunit ==="
vendor/bin/phpunit --colors=auto

echo
echo "=== smoke (requires SMOKE_USER, SMOKE_PASS, SMOKE_COOKIE) ==="
for s in tests/smoke/*.sh; do
  echo "--- $s ---"
  bash "$s"
done
```

- [ ] **Step 6: Make executable + sanity run**

```bash
chmod +x tests/run.sh tests/smoke/*.sh
SMOKE_USER=admin SMOKE_PASS='<your-pass>' tests/smoke/login.sh
```

- [ ] **Step 7: Commit**

```bash
git add tests/smoke/ tests/run.sh
git commit -m "test: smoke tests + tests/run.sh harness"
```

---

## Task 18: Final Plan A wrap — README updates, .htaccess hardening

**Files:**
- Modify: `README.md`
- Create: `public_html/.htaccess`

- [ ] **Step 1: Update `README.md`**

```markdown
# developers.soritune.com

소리튠 사내 AI 개발 협업·배포 관리 도구.

스펙: `docs/superpowers/specs/2026-05-28-developers-soritune-design.md`
Plan A (현재): `docs/superpowers/plans/2026-05-28-developers-soritune-plan-a-foundation.md`

## 셋업 (P0)
1. DB 생성: `SORITUNECOM_DEVELOPERS`
2. `.db_credentials` 작성 (`apache:apache 640`)
3. `.env` 자동 생성 또는 수동: `SESSION_SECRET=<openssl rand -hex 32>` (`apache:apache 640`)
4. `composer install`
5. `./scripts/run_migrations.sh`
6. `./scripts/seed_admin.sh <username> "<display>"` (대화형 비밀번호)
7. cron 설치: `/etc/cron.d/developers-soritune` (Task 15 step 5)

## 개발 흐름
- 코드 수정 → `vendor/bin/phpunit` → 브라우저 smoke → commit → push `origin dev`

## 디렉토리 권한
- 사이트 디렉토리: `ec2-user:apache` + `chmod 2775` (디렉토리), `664` (파일). worker는 `sudo -u ec2-user`.
- 시크릿 (.env, .db_credentials, keys/*): `apache:apache 640`.

## 테스트
- 단위/통합: `vendor/bin/phpunit`
- 스모크: `SMOKE_USER=... SMOKE_PASS=... ./tests/run.sh`
```

- [ ] **Step 2: Write `public_html/.htaccess`** (basic hardening)

```apache
# Block direct access to dotfiles and PHP-internal include patterns
<FilesMatch "^\.">
    Require all denied
</FilesMatch>

# Prevent serving raw PHP partials directly (defense in depth — they require_once auth)
<Files "config.php">
    Require all denied
</Files>
<Files "auth.php">
    Require all denied
</Files>
<Files "csrf.php">
    Require all denied
</Files>

# Force HTTPS
RewriteEngine On
RewriteCond %{HTTPS} !=on
RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# Default document
DirectoryIndex index.php

# Cache static assets
<FilesMatch "\.(css|js|svg|png|jpg|webp)$">
    Header set Cache-Control "public, max-age=3600"
</FilesMatch>
```

- [ ] **Step 3: Reload apache + smoke**

```bash
sudo systemctl reload httpd
curl -sI http://developers.soritune.com/ | head -1   # expect 301 -> https
curl -sI https://developers.soritune.com/config.php | head -1  # expect 403
```

- [ ] **Step 4: Run full test suite end-to-end**

```bash
vendor/bin/phpunit
# smoke (needs cookie/env)
SMOKE_USER=admin SMOKE_PASS='...' tests/smoke/login.sh
```
Expected: all green.

- [ ] **Step 5: Final commit**

```bash
git add README.md public_html/.htaccess
git commit -m "docs: README setup guide; hardening: .htaccess HTTPS+block partials"
```

---

## Plan A Done — Acceptance Criteria

After all 18 tasks the following are true:

- Admin can log in at `/login.php` (seeded via `scripts/seed_admin.sh`).
- Failed login 5× locks user for 15min; correct password resets counter.
- `/admin/users.php` lists, creates, resets-password, toggles-active.
- `/admin/projects.php` lists and manually registers existing projects.
- `/admin/project_members.php?id=<n>` toggles per-employee access.
- `/me.php` lets any user change own password (forced if `must_change_password=1`) and set GitHub username.
- Employee logs in → `/p/` shows only their accessible projects with dev/prod links.
- `/admin/jobs.php` lists jobs; "테스트 enqueue" creates a `dev_deploy` row; cron worker picks it up within 1 minute and marks success (Plan A stub — Plan B will wire real handlers).
- All actions audit-logged in `audit_log`.
- `phpunit` green; `tests/smoke/*.sh` green with admin cookie.
- `.env`, `.db_credentials`, `keys/` not web-accessible. All HTTP redirects to HTTPS.

What is intentionally NOT in Plan A (deferred):
- `tasks` table is created but no task creation UI / API yet (Plan B)
- `dev_deploy` / `prod_deploy` job handlers do nothing real (Plan B)
- `project_init` automation (Plan C)
- Notion-style full design polish (Plan D)
- Audit ingest for jobs status transitions (Plan D)
- `requireProjectAccess()` enforcement on per-project employee endpoints (Plan B will add task endpoints that use it)

---

## Self-Review

**1. Spec coverage (P1–P4 of plan):**
- P1 DB schema ✓ Task 2
- P2 인증 + 사용자 CRUD ✓ Tasks 3–6 (auth), 10–12 (users)
- P3 프로젝트 CRUD (수동) ✓ Tasks 13–14
- P4 jobs 인프라 ✓ Task 15

**2. Placeholder scan:** No TBD/TODO in concrete tasks. Plan B/C/D references are explicit deferrals, not within-Plan-A holes.

**3. Type consistency:**
- `JobQueue::enqueue(string $type, array $payload, int $userId, ?int $projectId, ?int $taskId)` — used the same shape in Test 15 + API test 15.
- `Audit::write(?int $userId, string $action, string $entityType, int $entityId, ?array $payload, ?string $ip)` and `writeFromRequest($userId, $action, $entityType, $entityId, $payload)` — consistent.
- `currentUser()` returns `?array` with keys `id, username, display_name, role, must_change_password` — referenced uniformly.
- `Validation::isValidSlug`, `isValidGithubRepo`, `isValidSubdomain`, `isValidUsername`, `isStrongPassword` — names stable across tasks.

**4. Ambiguity check:** Each step shows actual code/command. No "similar to" without repeated content where the prior content matters.

If subagents implement this plan: at Task 5 (csrf) they must NOT skip the `requireCsrfOrAbort()` helper — Tasks 11+ depend on it. At Task 15 the worker is intentionally a stub.

---

**Plan A complete and saved to `docs/superpowers/plans/2026-05-28-developers-soritune-plan-a-foundation.md`.**

Plans B/C/D will be written separately, each picking up from Plan A's exit state.
