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

    public function testUsersColumns(): void
    {
        $db = getDB();
        $cols = $db->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['id','username','password_hash','display_name','role','github_username','active','failed_attempts','locked_until','must_change_password','last_login_at','created_at','updated_at'] as $c) {
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

    public function testJobsStatusEnum(): void
    {
        $db = getDB();
        $col = $db->query("SHOW COLUMNS FROM jobs LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        foreach (['pending','running','success','failed','canceled'] as $s) {
            $this->assertStringContainsString("'$s'", $col['Type']);
        }
    }

    public function testProjectsCardTintEnum(): void
    {
        $db = getDB();
        $col = $db->query("SHOW COLUMNS FROM projects LIKE 'card_tint'")->fetch(PDO::FETCH_ASSOC);
        foreach (['peach','rose','mint','lavender','sky','yellow'] as $t) {
            $this->assertStringContainsString("'$t'", $col['Type']);
        }
    }

    public function testProjectsStatusEnum(): void
    {
        $db = getDB();
        $col = $db->query("SHOW COLUMNS FROM projects LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
        foreach (['provisioning','active','archived'] as $s) {
            $this->assertStringContainsString("'$s'", $col['Type']);
        }
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
