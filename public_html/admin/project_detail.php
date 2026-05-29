<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';
$u = requireAdmin();
$token = csrfToken();
$pid = (int)($_GET['id'] ?? 0);
if ($pid <= 0) { header('Location: /admin/projects.php'); exit; }
?>
<!doctype html>
<html lang="ko"><head>
<meta charset="utf-8"><title>프로젝트 상세 — developers.soritune.com</title>
<link rel="stylesheet" href="/assets/style.css?v=3">
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
  <a class="back-link" href="/admin/projects.php">← 프로젝트로</a>
  <header class="page-header">
    <h1 id="pname">프로젝트 상세</h1>
    <button id="refreshBtn">새로고침</button>
  </header>
  <div id="content"><p>불러오는 중…</p></div>
</main>
<script>
const pid = <?= json_encode($pid) ?>;
function escape(s) { return (s||'').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]); }

function gitCard(title, g) {
  if (!g || !g.ok) return `<div class="stat-card"><h3>${escape(title)}</h3><p class="bad">${escape((g&&g.error)||'읽기 실패')}</p></div>`;
  const rows = (g.log||[]).map(c => `<li><code>${escape(c.sha)}</code> ${escape(c.subject)} <span class="muted">${escape((c.date||'').slice(0,10))}</span></li>`).join('');
  return `<div class="stat-card">
    <h3>${escape(title)} <span class="muted">(${escape(g.branch||'')})</span></h3>
    <p><code>${escape(g.head)}</code> ${escape(g.subject||'')} <span class="muted">${escape(g.author||'')}</span></p>
    <ul class="commit-log">${rows}</ul></div>`;
}
function siteBadge(s) {
  if (!s) return '<span class="badge">?</span>';
  return s.up ? `<span class="badge ok">UP ${escape(String(s.code||''))}</span>` : '<span class="badge bad">응답 없음</span>';
}

async function load() {
  const content = document.getElementById('content');
  content.innerHTML = '<p>불러오는 중…</p>';
  let j;
  try { j = await (await fetch(`/api/system.php?action=project_status&op=get&id=${pid}`)).json(); }
  catch (e) { content.innerHTML = '<p class="bad">불러오기 실패</p>'; return; }
  if (!j.ok) { content.innerHTML = `<p class="bad">${escape(j.message||'불러오기 실패')}</p>`; return; }

  document.getElementById('pname').textContent = j.project.name + ' (' + j.project.slug + ')';

  let undep = '';
  if (j.undeployed && j.undeployed.ok) {
    if (j.undeployed.count === 0) undep = '<p class="ok">미배포 없음 (dev = 운영 기준)</p>';
    else undep = `<p class="warn">미배포 ${j.undeployed.count}건 (dev 가 main 보다 앞섬)</p><ul class="commit-log">` +
      (j.undeployed.commits||[]).map(c=>`<li><code>${escape(c.sha)}</code> ${escape(c.subject)}</li>`).join('') + '</ul>';
  } else {
    undep = `<p class="muted">미배포 비교 불가: ${escape((j.undeployed&&j.undeployed.error)||'')}</p>`;
  }

  let logHtml;
  if (j.log && j.log.ok) {
    logHtml = '<pre class="logbox">' + (j.log.lines||[]).map(escape).join('\n') + '</pre>';
  } else {
    logHtml = `<p class="muted">배포 로그 ${escape((j.log&&j.log.error)||'미설정')}</p>`;
  }

  content.innerHTML = `
    <section class="meta">
      <p><strong>repo:</strong> <code>${escape(j.project.github_repo)}</code></p>
      <p><strong>dev:</strong> ${siteBadge(j.sites.dev)} <code>${escape(j.project.dev_dir)}</code></p>
      <p><strong>운영:</strong> ${siteBadge(j.sites.prod)} <code>${escape(j.project.prod_dir)}</code></p>
    </section>
    <section class="git-cards">${gitCard('개발 (dev)', j.dev)}${gitCard('운영 (prod)', j.prod)}</section>
    <section><h2>미배포</h2>${undep}</section>
    <section><h2>배포 로그</h2>${logHtml}</section>
  `;
}
document.getElementById('refreshBtn').onclick = load;
load();
</script>
</body></html>
