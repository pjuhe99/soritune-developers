<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
$u = requireAuth();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>내 정보 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css?v=3">
<meta name="csrf-token" content="<?= e($token) ?>">
</head><body>
<nav class="topnav">
  <strong>developers.soritune.com</strong>
  <a href="/">홈</a>
  <a href="/me.php">내 정보</a>
  <span class="grow"></span><span><?= e($u['display_name']) ?></span>
  <a href="/logout.php">로그아웃</a>
</nav>
<main class="page">
  <h1>내 정보</h1>
  <section>
    <h2>비밀번호 변경</h2>
    <form id="pwForm">
      <label>현재 비밀번호 <input name="old_password" type="password" required></label>
      <label>새 비밀번호 (12자+) <input name="new_password" type="password" required minlength="12"></label>
      <button>변경</button>
    </form>
  </section>
  <section>
    <h2>GitHub 사용자명</h2>
    <form id="ghForm">
      <label>GitHub username <input name="github_username"></label>
      <button>저장</button>
    </form>
  </section>
</main>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
document.getElementById('pwForm').onsubmit = async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target); fd.set('_csrf', csrf);
  const j = await (await fetch('/api/user.php?action=me&op=change_password', { method:'POST', body: fd })).json();
  alert(j.ok ? '변경되었습니다.' : j.message);
  if (j.ok) e.target.reset();
};
document.getElementById('ghForm').onsubmit = async (e) => {
  e.preventDefault();
  const fd = new FormData(e.target); fd.set('_csrf', csrf);
  const j = await (await fetch('/api/user.php?action=me&op=set_github_username', { method:'POST', body: fd })).json();
  alert(j.ok ? '저장되었습니다.' : j.message);
};
</script>
</body></html>
