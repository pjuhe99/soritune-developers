<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$mask = function (string $v): string {
    $v = trim($v);
    if ($v === '') return '(미설정)';
    return strlen($v) > 8 ? '****' . substr($v, -4) : '****';
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
  <p class="hint">시크릿은 서버 <code>.env</code> 파일(apache:apache 640)에만 저장됩니다. 이 화면은 마스킹만 표시합니다.</p>
  <table class="data-table">
    <tr><th>GITHUB_ACCOUNT</th><td><?= e($env['GITHUB_ACCOUNT'] ?? '') ?: '(미설정)' ?></td></tr>
    <tr><th>GITHUB_ACCOUNT_TYPE</th><td><?= e($env['GITHUB_ACCOUNT_TYPE'] ?? 'user') ?></td></tr>
    <tr><th>GITHUB_TOKEN</th><td><?= e($mask($env['GITHUB_TOKEN'] ?? '')) ?></td></tr>
  </table>
</main>
</body></html>
