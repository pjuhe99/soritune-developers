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

        self::$devDir  = sys_get_temp_dir() . '/ps_dev_'  . bin2hex(random_bytes(4));
        self::$prodDir = sys_get_temp_dir() . '/ps_prod_' . bin2hex(random_bytes(4));
        foreach ([self::$devDir, self::$prodDir] as $d) {
            mkdir($d);
            $g = "git -C " . escapeshellarg($d) . " -c user.email=t@t -c user.name=t -c init.defaultBranch=main";
            shell_exec("$g init -q 2>&1");
            file_put_contents($d . '/a.txt', '1');
            shell_exec("$g add -A 2>&1 && $g commit -q -m init 2>&1");
        }
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
