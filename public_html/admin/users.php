<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>사용자 — developers.soritune.com</title>
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
  <header class="page-header">
    <h1>사용자</h1>
    <button id="btnNew">+ 새 사용자</button>
  </header>
  <table class="data-table">
    <thead><tr><th>ID</th><th>아이디</th><th>이름</th><th>역할</th><th>GitHub</th><th>활성</th><th>마지막 로그인</th><th>액션</th></tr></thead>
    <tbody id="tbody"><tr><td colspan="8">불러오는 중…</td></tr></tbody>
  </table>
</main>
<dialog id="newDlg">
  <form id="newForm" method="dialog">
    <h2>새 사용자</h2>
    <label>아이디 <input name="username" required pattern="[a-z][a-z0-9_]{2,63}"></label>
    <label>이름 <input name="display_name" required></label>
    <label>역할
      <select name="role"><option value="employee">직원</option><option value="admin">관리자</option></select>
    </label>
    <label>GitHub username <input name="github_username"></label>
    <label>임시 비밀번호 (12자+) <input name="temp_password" type="password" required minlength="12"></label>
    <menu><button value="cancel">취소</button><button id="newSubmit" value="ok">생성</button></menu>
  </form>
</dialog>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const tbody = document.getElementById('tbody');
async function load() {
  const r = await fetch('/api/system.php?action=users&op=list', { headers: { 'Accept':'application/json' } });
  const j = await r.json();
  if (!j.ok || !Array.isArray(j.users)) {
    tbody.innerHTML = `<tr><td colspan="8">불러오기 실패: ${escape(j.message || '세션이 만료되었을 수 있습니다. 새로고침하세요.')}</td></tr>`;
    return;
  }
  tbody.innerHTML = j.users.map(u => `
    <tr>
      <td>${u.id}</td>
      <td>${escape(u.username)}</td>
      <td>${escape(u.display_name)}</td>
      <td>${u.role}</td>
      <td>${escape(u.github_username || '')}</td>
      <td>${u.active ? '✓' : '–'}</td>
      <td>${u.last_login_at || ''}</td>
      <td>
        <button data-act="reset" data-id="${u.id}">비번 재설정</button>
        <button data-act="toggle" data-id="${u.id}" data-cur="${u.active}">${u.active ? '비활성화' : '활성화'}</button>
      </td>
    </tr>
  `).join('');
}
function escape(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

document.getElementById('btnNew').onclick = () => newDlg.showModal();
document.getElementById('newSubmit').onclick = async (e) => {
  // preventDefault FIRST: form[method=dialog] would otherwise close the modal on
  // submit before validation can block it (the native gate doesn't apply here).
  e.preventDefault();
  const form = document.getElementById('newForm');
  if (!form.reportValidity()) return;
  const fd = new FormData(form);
  fd.append('_csrf', csrf);
  const r = await fetch('/api/system.php?action=users&op=create', { method:'POST', body: fd });
  const j = await r.json();
  if (!j.ok) { alert(j.message); return; }
  form.reset();
  newDlg.close();
  load();
};
tbody.onclick = async (e) => {
  const btn = e.target.closest('button[data-act]');
  if (!btn) return;
  const id = btn.dataset.id;
  if (btn.dataset.act === 'reset') {
    const pw = prompt('새 임시 비밀번호 (12자+):');
    if (!pw) return;
    const fd = new FormData(); fd.set('_csrf', csrf); fd.set('user_id', id); fd.set('new_password', pw);
    const j = await (await fetch('/api/system.php?action=users&op=reset_password', { method:'POST', body: fd })).json();
    alert(j.ok ? '재설정 완료' : j.message);
  } else if (btn.dataset.act === 'toggle') {
    const fd = new FormData(); fd.set('_csrf', csrf); fd.set('user_id', id); fd.set('active', btn.dataset.cur === '1' ? 0 : 1);
    const j = await (await fetch('/api/system.php?action=users&op=set_active', { method:'POST', body: fd })).json();
    if (j.ok) load(); else alert(j.message);
  }
};
load();
</script>
</body></html>
