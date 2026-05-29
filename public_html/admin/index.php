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
<link rel="stylesheet" href="/assets/style.css?v=2">
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
