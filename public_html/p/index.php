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
        <li class="project-card tint-<?= e($p['card_tint'] ?? '') ?>">
          <h2><?= e($p['name'] ?? '') ?></h2>
          <p><?= e($p['description'] ?? '') ?></p>
          <div class="urls">
            <a href="https://<?= e($p['dev_subdomain'] ?? '') ?>" target="_blank">개발 화면</a>
            <a href="https://<?= e($p['prod_subdomain'] ?? '') ?>" target="_blank">운영 화면</a>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</main>
</body></html>
