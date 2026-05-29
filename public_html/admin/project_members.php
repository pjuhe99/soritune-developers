<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
$projectId = (int)($_GET['id'] ?? 0);
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>멤버 관리 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css?v=2">
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
  <a href="/admin/projects.php">← 프로젝트로</a>
  <h1 id="title">멤버 관리</h1>
  <section>
    <h2>현재 멤버</h2>
    <ul id="members"></ul>
  </section>
  <section>
    <h2>접근 권한 부여</h2>
    <select id="userSelect"></select>
    <button id="grantBtn">권한 부여</button>
  </section>
</main>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const pid = <?= json_encode($projectId) ?>;
async function load() {
  const j = await (await fetch(`/api/system.php?action=projects&op=get&id=${pid}`)).json();
  if (!j.ok) { document.getElementById('title').textContent = '멤버 관리 — ' + (j.message || '불러오기 실패'); return; }
  document.getElementById('title').textContent = `멤버 관리 — ${j.project.name}`;
  document.getElementById('members').innerHTML = j.members.map(m => `
    <li>${escape(m.display_name)} (${escape(m.username)})
      <button data-uid="${m.id}">제거</button></li>
  `).join('') || '<li>아직 멤버가 없습니다.</li>';
  const uj = await (await fetch('/api/system.php?action=users&op=list')).json();
  if (!uj.ok || !Array.isArray(uj.users)) return;
  document.getElementById('userSelect').innerHTML = uj.users
    .filter(u => u.active)
    .map(u => `<option value="${u.id}">${escape(u.display_name)} (${escape(u.username)})</option>`).join('');
}
function escape(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
document.getElementById('grantBtn').onclick = async () => {
  const uid = document.getElementById('userSelect').value;
  if (!uid) { alert('부여할 사용자를 선택하세요.'); return; }
  const fd = new FormData(); fd.set('_csrf', csrf); fd.set('project_id', pid); fd.set('user_id', uid);
  const j = await (await fetch('/api/system.php?action=projects&op=grant_access', { method:'POST', body: fd })).json();
  if (j.ok) load(); else alert(j.message);
};
document.getElementById('members').onclick = async (e) => {
  const btn = e.target.closest('button[data-uid]');
  if (!btn) return;
  const fd = new FormData(); fd.set('_csrf', csrf); fd.set('project_id', pid); fd.set('user_id', btn.dataset.uid);
  const j = await (await fetch('/api/system.php?action=projects&op=revoke_access', { method:'POST', body: fd })).json();
  if (j.ok) load(); else alert(j.message);
};
load();
</script>
</body></html>
