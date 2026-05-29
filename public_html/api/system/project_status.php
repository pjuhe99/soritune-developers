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
