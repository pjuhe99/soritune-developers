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
        $row = $db->query("SELECT status,dev_dir,dev_db_name FROM projects WHERE id=$pid")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('provisioning', $row['status']);
        $this->assertSame("/var/www/html/_______site_SORITUNECOM_DEV_" . strtoupper(str_replace('-','_',$slug)) . "/public_html", $row['dev_dir']);
        $this->assertSame("SORITUNECOM_DEV_" . strtoupper(str_replace('-','_',$slug)), $row['dev_db_name']);
        $job = $db->query("SELECT type,status FROM jobs WHERE project_id=$pid")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('project_init', $job['type']);
        $this->assertSame('pending', $job['status']);
    }

    public function testInitDuplicateSlugRejected(): void
    {
        $slug = 'cinit' . bin2hex(random_bytes(3));
        $p = ['slug'=>$slug,'name'=>'Dup','dev_subdomain'=>"dev-$slug.soritune.com",'prod_subdomain'=>"$slug.soritune.com",'member_ids'=>''];
        $first = $this->call($p);
        $this->assertTrue($first['ok'] ?? false, json_encode($first));
        $dup = $this->call($p);
        $this->assertFalse($dup['ok'] ?? true);
    }
}
