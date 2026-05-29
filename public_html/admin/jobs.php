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
<link rel="stylesheet" href="/assets/style.css?v=4">
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
    <button id="btnEnq">테스트 작업 추가</button>
  </header>
  <table class="data-table">
    <thead><tr><th>ID</th><th>종류</th><th>상태</th><th>요청자</th><th>생성</th><th>완료</th><th>에러</th></tr></thead>
    <tbody id="tbody"><tr><td colspan="7">불러오는 중…</td></tr></tbody>
  </table>
</main>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
async function load() {
  const j = await (await fetch('/api/system.php?action=jobs&op=list')).json();
  const tb = document.getElementById('tbody');
  if (!j.ok || !Array.isArray(j.jobs)) { tb.innerHTML = `<tr><td colspan="7">불러오기 실패: ${escape(j.message||'새로고침하세요.')}</td></tr>`; return; }
  if (!j.jobs.length) { tb.innerHTML = '<tr><td colspan="7">작업이 없습니다.</td></tr>'; return; }
  tb.innerHTML = j.jobs.map(x => `
    <tr>
      <td>${x.id}</td><td>${escape(x.type)}</td><td>${escape(x.status)}</td>
      <td>${escape(x.actor||'')}</td><td>${escape(x.enqueued_at||'')}</td>
      <td>${escape(x.finished_at||'')}</td><td>${escape(x.error_message||'')}</td>
    </tr>
  `).join('');
}
function escape(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
document.getElementById('btnEnq').onclick = async () => {
  const fd = new FormData(); fd.set('_csrf', csrf);
  const j = await (await fetch('/api/system.php?action=jobs&op=enqueue_test', { method:'POST', body: fd })).json();
  if (!j.ok) { alert(j.message); return; }
  alert(`작업 #${j.job_id} 추가됨 — 1분 내 워커가 처리합니다.`);
  load();
};
load();
</script>
</body></html>
