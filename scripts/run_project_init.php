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
if (!is_array($p) || empty($p['project_id']) || empty($p['slug'])
    || empty($p['dev_subdomain']) || empty($p['prod_subdomain'])
    || !isset($p['dev_bare'], $p['prod_bare'])) {
    JobQueue::markDone($jobId, false, 'invalid project_init payload');
    fwrite(STDERR, "invalid payload\n"); exit(1);
}
$env = loadEnv();
$redact = function (string $s) use ($env): string {
    $tok = (string)($env['GITHUB_TOKEN'] ?? '');
    return ($tok !== '' && $s !== '') ? str_replace($tok, '***', $s) : $s;
};
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

// 3. DNS propagation wait (non-fatal — local resolver may negative-cache; real check is issue_ssl)
for ($i=0;$i<18;$i++){ $g=$runner->run('getent hosts '.escapeshellarg($p['dev_bare'].'.soritune.com').' 2>/dev/null'); if($g['code']===0)break; sleep(10); }

// 4-5. site_manager dev + prod
$smc = new SiteManagerClient('/var/www/html/_______site_SORITUNECOM_APP/jobs/pending','/var/www/html/_______site_SORITUNECOM_APP/jobs/done', 300, 3_000_000);
$pdev = $smc->provision($p['dev_bare']);  if (!$pdev['ok']) $fail('site_dev', $pdev['error'] ?? ''); $result['dev_site']=$pdev;
$pprod= $smc->provision($p['prod_bare']); if (!$pprod['ok']) $fail('site_prod', $pprod['error'] ?? ''); $result['prod_site']=$pprod;

// 6. clone into public_html (temp clone then move; site_manager already put a default index.php there)
$devN = ProjectNaming::fromSubdomain($p['dev_subdomain']);
$prodN= ProjectNaming::fromSubdomain($p['prod_subdomain']);
$cloneInto = function(string $codeDir, string $branch) use ($runner,$env,$full,$fail,$redact){
    if (is_dir("$codeDir/.git")) return; // already cloned (idempotent)
    $tmp = sys_get_temp_dir().'/pcinit_'.bin2hex(random_bytes(5));
    $tokUrl = 'https://x-access-token:'.($env['GITHUB_TOKEN']??'').'@github.com/'.$full.'.git';
    $cmd = 'GIT_TERMINAL_PROMPT=0 git clone --branch '.escapeshellarg($branch)
         .' '.escapeshellarg($tokUrl).' '.escapeshellarg($tmp).' 2>&1';
    $c = $runner->run($cmd);
    if ($c['code']!==0){ $runner->run('rm -rf '.escapeshellarg($tmp)); $fail('clone_'.$branch, $redact(trim($c['out']))); }
    // replace the site_manager default index.php with the repo contents (preserve nothing in public_html)
    $runner->run('rm -f '.escapeshellarg("$codeDir/index.php"));
    $cp = $runner->run('cp -a '.escapeshellarg($tmp).'/. '.escapeshellarg($codeDir).'/ 2>&1');
    $runner->run('rm -rf '.escapeshellarg($tmp));
    if ($cp['code'] !== 0) { $fail('clone_move_'.$branch, $redact(trim($cp['out']))); }
};
$cloneInto($devN['code_dir'],'dev');
$cloneInto($prodN['code_dir'],'main');
$result['clone']='ok';

// 7. projects active
$devHead = trim($runner->run('git -C '.escapeshellarg($devN['code_dir']).' -c safe.directory='.escapeshellarg($devN['code_dir']).' rev-parse HEAD 2>/dev/null')['out']);
$prodHead= trim($runner->run('git -C '.escapeshellarg($prodN['code_dir']).' -c safe.directory='.escapeshellarg($prodN['code_dir']).' rev-parse HEAD 2>/dev/null')['out']);
$db->prepare("UPDATE projects SET status='active', last_synced_commit=?, last_prod_commit=? WHERE id=?")
   ->execute([$devHead ?: null, $prodHead ?: null, $p['project_id']]);
JobQueue::markDone($jobId, true, null, $result + ['repo_url'=>$repo['repo_url'] ?? '']);
echo "OK\n";
