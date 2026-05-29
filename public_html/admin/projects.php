<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>프로젝트 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css">
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
    <h1>프로젝트</h1>
    <button id="btnNew">+ 프로젝트 등록</button>
  </header>
  <div id="cards" class="project-list"><p>불러오는 중…</p></div>
</main>
<dialog id="regDlg">
  <form id="regForm" method="dialog">
    <h2>프로젝트 등록 (수동)</h2>
    <p class="hint">기존에 만들어진 사이트를 포털에 연결만 합니다. 실제 생성/배포는 Plan B/C에서.</p>
    <label>슬러그 <input name="slug" required pattern="[a-z][a-z0-9-]{1,38}"></label>
    <label>이름 <input name="name" required></label>
    <label>GitHub repo <input name="github_repo" required placeholder="pjuhe99/soritune-camp"></label>
    <label>dev 서브도메인 <input name="dev_subdomain" required placeholder="camp-dev.soritune.com"></label>
    <label>운영 서브도메인 <input name="prod_subdomain" required placeholder="camp.soritune.com"></label>
    <label>dev 디렉토리 <input name="dev_dir" required placeholder="/var/www/html/..._DEV_CAMP"></label>
    <label>운영 디렉토리 <input name="prod_dir" required placeholder="/var/www/html/..._CAMP"></label>
    <label>dev DB <input name="dev_db_name" required></label>
    <label>운영 DB <input name="prod_db_name" required></label>
    <label>색상
      <select name="card_tint">
        <option>peach</option><option>rose</option><option>mint</option>
        <option>lavender</option><option>sky</option><option>yellow</option>
      </select>
    </label>
    <menu><button value="cancel">취소</button><button id="regSubmit" value="ok">등록</button></menu>
  </form>
</dialog>
<script>
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const cards = document.getElementById('cards');
async function load() {
  const j = await (await fetch('/api/system.php?action=projects&op=list')).json();
  if (!j.ok || !Array.isArray(j.projects)) { cards.innerHTML = `<p>불러오기 실패: ${escape(j.message || '세션이 만료되었을 수 있습니다. 새로고침하세요.')}</p>`; return; }
  if (!j.projects.length) { cards.innerHTML = '<p>아직 등록된 프로젝트가 없습니다.</p>'; return; }
  cards.innerHTML = j.projects.map(p => `
    <div class="project-card tint-${escape(p.card_tint)}">
      <h2>${escape(p.name)}</h2>
      <p class="slug">${escape(p.slug)} · ${escape(p.status)}</p>
      <div class="urls">
        <a href="https://${escape(p.dev_subdomain)}" target="_blank" rel="noopener noreferrer">개발</a>
        <a href="https://${escape(p.prod_subdomain)}" target="_blank" rel="noopener noreferrer">운영</a>
      </div>
      <div class="card-actions">
        <a href="/admin/project_members.php?id=${p.id}">멤버 관리</a>
        ${p.status === 'active' ? `<button data-act="archive" data-id="${p.id}">보관</button>` : ''}
      </div>
    </div>
  `).join('');
}
function escape(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }
document.getElementById('btnNew').onclick = () => regDlg.showModal();
document.getElementById('regSubmit').onclick = async (e) => {
  e.preventDefault();
  const form = document.getElementById('regForm');
  if (!form.reportValidity()) return;
  const fd = new FormData(form);
  fd.append('_csrf', csrf);
  const j = await (await fetch('/api/system.php?action=projects&op=register', { method:'POST', body: fd })).json();
  if (!j.ok) { alert(j.message); return; }
  form.reset();
  regDlg.close(); load();
};
cards.onclick = async (e) => {
  const btn = e.target.closest('button[data-act="archive"]');
  if (!btn) return;
  if (!confirm('이 프로젝트를 보관하시겠습니까?')) return;
  const fd = new FormData(); fd.set('_csrf', csrf); fd.set('project_id', btn.dataset.id);
  const j = await (await fetch('/api/system.php?action=projects&op=archive', { method:'POST', body: fd })).json();
  if (j.ok) load(); else alert(j.message);
};
load();
</script>
</body></html>
