# developers.soritune.com — 사내 AI 개발 협업·배포 관리 페이지 설계서

- 작성일: 2026-05-28
- 원본 요청 스펙: `/root/developers-soritune-spec-2026-05-28.md`
- 진행 메모리: `developers-soritune-design-wip` (brainstorming)
- 작성자/검토자: 박주희 (소리튠 운영자, 관리자 1인)

---

## 0. 한 줄 요약

비개발자 직원 3~5명이 각자 PC 의 Claude Code / Codex 로 만든 PHP+DB 미니 프로젝트를, GitHub·서버 명령어를 모르고도 **공유 dev 브랜치 → 관리자가 현재 개발본 검토 → 운영 반영** 흐름으로 배포할 수 있게 해주는 사내 관리 페이지. 모든 비동기 액션을 하나의 `jobs` 큐로 통합하여 진행상황과 감사가 한 곳에 모인다.

## 1. 결정 사항 (확정)

| # | 항목 | 결정 |
|---|---|---|
| 1 | 일반 운영 방향 | 신규 미니 프로젝트 1차 파일럿. 기존 junior/pt 통합은 future |
| 2 | 사용자 수 | 관리자 1 + 일반 사용자 3~5 |
| 3 | 프로젝트 형태 | PHP+DB 풀스택 (junior/pt/boot 스타일), 빌드 단계 없음 |
| 4 | 직원 작업환경 | 직원 PC 직접 설치 (git + Claude Code/Codex). 사용자가 별도 도와줌 |
| 5 | 인증 | 사내 ID/PW (junior 어드민 패턴 차용) |
| 6 | GitHub repo 생성 | GitHub App 신규 등록 |
| 7 | 작업 브랜치 모델 | 프로젝트당 공유 `dev` 브랜치 (직원 모두 push) |
| 8 | DNS A 레코드 | Route53 API 자동 (소속 `soritune.com` 만) |
| 9 | 프로젝트 템플릿 | PHP+DB 1종 보일러플레이트 |
| 10 | MVP 범위 | 스펙 §5-1~5-4 필수, §5-5(PC 셋업)는 가이드 페이지만 |
| 11 | 배포 엔진 | 단일 jobs 큐로 통합 (Approach A) |
| 12 | UI 디자인 시스템 | Notion-style (Pretendard for 한글) |
| 13 | **운영 반영 단위** | **task 가 아니라 프로젝트 현재 dev HEAD** (공유 dev 와 자연스러움). task 는 "내 작업" 추적·검토요청 마커로만 |
| 14 | **dev 자동 동기화** | **없음**. 명시적 트리거만 (직원 [개발 화면에 반영하기] / 관리자 [지금 dev 동기화]) |
| 15 | **working tree 권한** | **`ec2-user:apache` + setgid 디렉토리 (2775) + 파일 664**. ec2-user 가 git, apache 그룹이 PHP-FPM read. 메모리 [[root-owned-files-in-ec2user-dirs]] 함정 회피 |
| 16 | **GitHub branch protection** | `project_init` 이 ruleset 3개 자동 등록: main(direct push/force/delete 차단, admin bypass), dev(force/delete 차단), ref name 제한(`main\|dev` 만) |

## 2. 아키텍처 개요

```
┌────────────────────────────────────────────────────────────────┐
│ developers.soritune.com  (PHP 풀스택, junior 패턴 차용)        │
│   ├─ public_html/  (관리 UI)                                   │
│   ├─ api/                                                      │
│   ├─ config.php + .db_credentials                              │
│   └─ .env + keys/  (시크릿, apache:apache 640)                 │
└────────────────────────────────────────────────────────────────┘
            │ INSERT/POLL                  ▲ UPDATE
            ▼                              │
┌────────────────────────────────────────────────────────────────┐
│ jobs 테이블 (DB) + jobs/{pending,running,done,logs}/ (file)    │
│   type: project_init | site_create | dev_deploy | prod_deploy  │
│         | user_repo_grant                                      │
└────────────────────────────────────────────────────────────────┘
            ▲ flock, 매 분 cron
┌────────────────────────────────────────────────────────────────┐
│ developers_worker.sh (cron, root)                              │
│   ├─ project_init  → GitHub App + Route53 + site_manager       │
│   ├─ site_create   → site_manager.sh                           │
│   ├─ dev_deploy    → sudo -u ec2-user git pull origin dev      │
│   ├─ prod_deploy   → dev→main ff-merge + push + prod pull      │
│   └─ user_repo_grant → GitHub App collaborator add             │
└────────────────────────────────────────────────────────────────┘
            ├─ GitHub App API (repo, branch, collaborator)
            ├─ Route53 API   (A 레코드)
            └─ site_manager.sh (vhost + certbot + DB)
```

**원칙 3가지**

1. **관리페이지 자체는 1차 파일럿이다** — 사용자가 junior/pt 처럼 수동 git pull 로 운영. 닭/달걀 회피.
2. **모든 비동기 액션은 job 1개** — UI 는 `jobs.id` 만 폴링하면 됨. 로그·실패·재시도가 한 군데.
3. **운영 반영은 관리자 only** — 일반 사용자 UI 에 prod_deploy 버튼 자체가 없음.

## 3. 메뉴 구조

### 3.1 일반 사용자 화면

| 경로 | 화면 | 핵심 |
|---|---|---|
| `/` | 홈 | 내 프로젝트 카드(pastel tint) — dev/prod URL + 내 작업 N건 |
| `/p/<project>` | 프로젝트 상세 | dev URL 큰 버튼, 시작 가이드 링크, "새 작업 등록" |
| `/p/<project>/tasks/new` | 새 작업 등록 | 작업명·설명 입력 |
| `/p/<project>/tasks/<id>` | 내 작업 상세 | `[개발 화면에 반영하기]` `[개발 화면 확인하기]` `[관리자 검토 요청하기]` + 상태 한 줄 + 최근 5개 job. 안내 문구: "여러분이 [반영하기] 를 누르면 다른 동료의 작업도 함께 dev 에 올라옵니다" |
| `/guide/start` | 시작 가이드 | 6단계 + Claude Code 설치 안내 |
| `/me` | 내 정보 | 비번 변경, GitHub username, 메모 |

### 3.2 관리자 화면

| 경로 | 화면 | 핵심 |
|---|---|---|
| `/admin` | 대시보드 | "운영 반영 대기 N개 프로젝트" 옐로우 배너, 진행중 job, 최근 배포 |
| `/admin/projects` | 프로젝트 목록 | 표 + "새 프로젝트" CTA |
| `/admin/projects/new` | 프로젝트 생성 마법사 | 슬러그·이름·dev/prod 서브도메인·접근자 → `project_init` |
| `/admin/projects/<slug>` | 프로젝트 상세 | repo URL, 디렉토리, 접근 관리, [지금 dev 동기화] (task_id=null dev_deploy), [현재 dev 를 운영으로] (prod_deploy), 검토 요청된 task 목록·메모, dev HEAD 의 `git log --oneline -20` |
| `/admin/users` | 사용자·권한 | 발급, reset, 프로젝트 toggle |
| `/admin/reviews` | 운영 반영 검토 | **프로젝트별 카드**. 각 카드: dev HEAD commit, 검토 요청된 task 목록(작업명·요청자), dev 마지막 동기화 시각, 액션: `[개발 확인]` `[현재 dev 를 운영으로]` `[수정 요청]`. 단위는 task 가 아닌 **프로젝트** |
| `/admin/jobs` | 모든 job | type·status filter |
| `/admin/deploys` | 배포 이력 | `jobs WHERE type IN ('dev_deploy','prod_deploy')` view |
| `/admin/settings` | 시크릿 상태 | 마스킹된 메타데이터만 (값 노출 X) |

## 4. 데이터 모델

### 4.1 핵심 6개 테이블 (MariaDB, DB=`SORITUNECOM_DEVELOPERS`)

```sql
users (
  id INT PK AUTO_INCREMENT,
  username VARCHAR(64) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  role ENUM('admin','employee') NOT NULL,
  github_username VARCHAR(80) NULL,
  active TINYINT(1) DEFAULT 1,
  failed_attempts INT DEFAULT 0,
  locked_until TIMESTAMP NULL,
  must_change_password TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  last_login_at TIMESTAMP NULL
);

projects (
  id INT PK AUTO_INCREMENT,
  slug VARCHAR(40) UNIQUE NOT NULL,        -- /^[a-z][a-z0-9-]{1,38}$/
  name VARCHAR(100) NOT NULL,
  description TEXT,
  github_repo VARCHAR(120) NOT NULL,       -- org/repo
  dev_subdomain VARCHAR(120) NOT NULL,
  prod_subdomain VARCHAR(120) NOT NULL,
  dev_dir VARCHAR(255) NOT NULL,
  prod_dir VARCHAR(255) NOT NULL,
  dev_db_name VARCHAR(64) NOT NULL,
  prod_db_name VARCHAR(64) NOT NULL,
  default_branch VARCHAR(40) NOT NULL DEFAULT 'dev',
  prod_branch VARCHAR(40) NOT NULL DEFAULT 'main',
  card_tint ENUM('peach','rose','mint','lavender','sky','yellow') DEFAULT 'peach',
  status ENUM('provisioning','active','archived') DEFAULT 'provisioning',
  last_synced_commit VARCHAR(40) NULL,     -- dev 서버에 마지막으로 pull 한 commit
  last_synced_at TIMESTAMP NULL,
  last_prod_commit VARCHAR(40) NULL,       -- prod 서버 현재 commit
  last_prod_deployed_at TIMESTAMP NULL,
  init_job_id INT NULL,
  created_by INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

project_access (
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  granted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  granted_by INT NOT NULL,
  PRIMARY KEY (project_id, user_id)
);

tasks (
  id INT PK AUTO_INCREMENT,
  project_id INT NOT NULL,
  user_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT,
  status ENUM(
    'drafting','dev_pending','dev_deploying','dev_ready',
    'review_requested','changes_requested','approved',
    'prod_deploying','prod_done','failed','on_hold'
  ) DEFAULT 'drafting',
  current_job_id INT NULL,
  last_dev_commit VARCHAR(40) NULL,
  last_prod_commit VARCHAR(40) NULL,
  last_dev_deploy_at TIMESTAMP NULL,        -- rate limit 용
  admin_comment TEXT NULL,
  reviewed_at TIMESTAMP NULL,
  approved_by INT NULL,
  approved_at TIMESTAMP NULL,
  prod_deployed_at TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (project_id, user_id),
  INDEX (status)
);

jobs (
  id INT PK AUTO_INCREMENT,
  type ENUM('project_init','site_create','dev_deploy','prod_deploy','user_repo_grant') NOT NULL,
  status ENUM('pending','running','success','failed','canceled') DEFAULT 'pending',
  project_id INT NULL,
  task_id INT NULL,
  user_id INT NOT NULL,                    -- enqueue 한 사람
  payload JSON NOT NULL,
  result JSON NULL,
  error_message TEXT NULL,
  log_path VARCHAR(255) NULL,
  attempts INT DEFAULT 0,
  enqueued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  started_at TIMESTAMP NULL,
  finished_at TIMESTAMP NULL,
  INDEX (status, type),
  INDEX (task_id),
  INDEX (project_id)
);

audit_log (
  id BIGINT PK AUTO_INCREMENT,
  user_id INT NULL,
  action VARCHAR(40) NOT NULL,             -- 'project.create','task.approve',...
  entity_type VARCHAR(20) NOT NULL,
  entity_id INT NOT NULL,
  payload JSON,
  ip VARCHAR(45),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (entity_type, entity_id),
  INDEX (user_id),
  INDEX (created_at)
);
```

### 4.2 jobs.payload 타입별 스키마

| type | payload | result |
|---|---|---|
| `project_init` | `{slug, name, description, dev_subdomain, prod_subdomain, template:'php_db', members:[user_id, ...]}` | `{repo_url, dev_url, prod_url, dev_dir, prod_dir, dev_db_name, prod_db_name, ruleset_ids:[...], skipped_members:[user_id, ...]}` |
| `site_create` | `{subdomain, target:'dev'\|'prod', project_id}` | `{url}` |
| `dev_deploy` | `{project_id, task_id?, branch:'dev'}` — `task_id` 있으면 직원 본인 작업 마킹용, 없으면 admin "지금 dev 동기화" | `{commit_before, commit_after, url}` |
| `prod_deploy` | `{project_id, dev_commit_sha}` — **프로젝트 단위**. 그 시점 dev HEAD 까지의 모든 review_requested task 가 일괄 prod_done 으로 천이 | `{prod_commit, prod_url, transitioned_task_ids:[...]}` |
| `user_repo_grant` | `{user_id, project_ids:[...]}` | `{added:[repo,...], skipped:[{repo, reason}, ...]}` |

`members` 중 `github_username` 미설정인 경우 → `skipped_members` 에 기록, audit_log 에도 남김 (가입 후 본인이 GitHub username 채우면 admin 이 `user_repo_grant` 로 후처리).

### 4.3 상태 전이 규칙

**중요**: task 의 상태는 "내 작업 진행도" 의 추적 단위이고, 운영 반영의 *원자 단위는 프로젝트의 dev HEAD* 다. 한 prod_deploy 가 그 시점 dev HEAD 까지의 모든 review_requested task 를 함께 prod_done 으로 전이시킨다.

```
[task 단위 천이]
drafting
  └── (POST /tasks/<id>/dev-deploy) → dev_pending → dev_deploying
       ├── (worker success) → dev_ready    -- task.last_dev_commit = dev HEAD
       └── (worker fail)    → failed       → (retry)

dev_ready / changes_requested
  └── (재반영) → dev_pending → ...

dev_ready
  └── (POST /tasks/<id>/request-review) → review_requested

review_requested
  ├── (admin: 수정요청)   → changes_requested  (admin_comment 채움)
  └── (admin 프로젝트 단위 [현재 dev 를 운영으로] 실행)
       → approved → prod_deploying
       ├── (worker success) → prod_done    -- task.last_prod_commit = main HEAD
       └── (worker fail)    → failed

any non-terminal
  └── (admin: 보류) → on_hold → (admin: 재개) → drafting
```

**프로젝트 단위 prod_deploy 의 일괄 전이**:
- 관리자가 `/admin/projects/<slug>` 또는 `/admin/reviews` 에서 [현재 dev 를 운영으로] 실행
- `INSERT jobs(type='prod_deploy', payload={project_id, dev_commit_sha=current_dev_HEAD})`
- 그 시점 `SELECT id FROM tasks WHERE project_id=X AND status='review_requested'` 한 task ID 들을 `task_ids` 로 worker 에 전달 (jobs.result 의 `transitioned_task_ids`)
- worker 성공 시 그 모든 task 들을 `prod_deploying → prod_done` 으로 일괄 UPDATE + `last_prod_commit` 세팅

**불변식 (DB 트리거 또는 PHP 게이트)**:
- `prod_deploying → prod_done` 전이는 **prod_deploy job success 만** 만들 수 있음
- `tasks.status='prod_done'` 인 row 는 UPDATE 불가 (`approved_by`, `last_prod_commit` 확정)
- prod_deploy 가 작동하려면 `tasks WHERE status='review_requested' AND project_id=X` 가 1개 이상 있어야 함 (admin 이 review 받은 작업이 0인 dev HEAD 를 운영 반영하는 건 차단 — UI 가드)

### 4.4 시크릿 (DB 가 아닌 파일)

| 자산 | 위치 | 권한 |
|---|---|---|
| DB 비번 | `.db_credentials` | `apache:apache 640` |
| 앱 비밀 (세션 키, GitHub App ID/installation ID, Route53 IAM) | `.env` | `apache:apache 640` |
| GitHub App private key | `keys/github-app.pem` | `apache:apache 640` |
| SSH key (배포 fetch 용) | `~ec2-user/.ssh/id_ed25519_<slug>` | `ec2-user:ec2-user 600` |

`/admin/settings` 는 `****ab12` 마스킹과 마지막 갱신일만 표시.

## 5. 배포 플로우 (각 job type 의 실제 동작)

### 5.1 worker 골격

```
/var/www/.../_______site_SORITUNECOM_DEVELOPERS/
  ├─ scripts/
  │   ├─ developers_worker.sh         # 매분 cron, flock
  │   ├─ job_project_init.sh
  │   ├─ job_dev_deploy.sh
  │   ├─ job_prod_deploy.sh
  │   ├─ job_site_create.sh
  │   ├─ job_user_repo_grant.sh
  │   └─ manual_rollback_project.sh   # 수동
  └─ jobs/
      ├─ pending/<id>.json
      ├─ running/<id>.json
      ├─ done/<id>.json
      ├─ done/<id>.result.json
      └─ logs/<id>.log
```

cron:

```cron
* * * * * root timeout 1800 flock -n /var/run/developers_worker.lock \
    /var/www/.../scripts/developers_worker.sh \
    >> /var/log/developers_worker.log 2>&1
```

- 동시 1개 worker (`flock -n`), 1 job at a time (단순화)
- 30분 timeout (`timeout 1800`) — hang 방어
- DB 가 진실, file marker 는 worker 진입점

### 5.2 project_init (마법사 1회)

```
1. GitHub App:
   - POST /orgs/<org>/repos  (private, default=main)
   - main 초기 commit (.gitignore + README + PHP+DB 템플릿 + dev 브랜치 작업 가이드 PROMPT.md)
   - dev 브랜치 생성 (main 에서)
   - collaborator add (members 의 github_username, role=push)
   - Branch ruleset 3개 등록 (POST /repos/<org>/<repo>/rulesets):
     ① main-protected:
        target refs/heads/main
        block direct push (bypass: org/repo admin)
        block deletion
        block force-push
     ② dev-protected:
        target refs/heads/dev
        block deletion
        block force-push
     ③ branch-restriction:
        target refs/heads/* (모든 신규 ref 생성)
        require ref name to match ^(main|dev)$
   - ruleset 등록 실패 시 → job failed (보안 invariant 차단). 부분 등록 후 실패면 result.ruleset_ids 에 partial 기록 + manual cleanup 안내
2. Route53: A 레코드 dev_subdomain + prod_subdomain → 서버 IP
3. site_manager.sh × 2 (DEV + PROD)
   - vhost + certbot + DB 생성 + .db_credentials
   - 큐 완료까지 폴링 (timeout 10분)
4. git clone (sudo -u ec2-user, /root/.ssh/<slug> SSH key 사용)
   - DEV: --branch dev
   - PROD: --branch main
5. **권한 설정** (한 곳에서 모두):
   - chown -R ec2-user:apache <dir>
   - find <dir> -type d -exec chmod 2775 {} \;   (setgid 로 새 디렉토리 group=apache 상속)
   - find <dir> -type f -exec chmod 664 {} \;
   - SELinux: `restorecon -R <dir>` (httpd_sys_content_t 기본 컨텍스트), 업로드 디렉토리만 `chcon -R -t httpd_sys_rw_content_t <upload_dir>`
6. 심볼릭 링크: /root/<slug>-dev, /root/<slug>-prod
7. **auto-deploy cron 등록 X** — 결정 14 에 따라 자동 동기화 없음. dev pull 은 오직 dev_deploy job 으로만.
8. UPDATE projects SET status='active', init_job_id, dev_dir, prod_dir, last_synced_commit=<dev HEAD>, last_synced_at=NOW(), last_prod_commit=<main HEAD>
9. result JSON 저장 (ruleset_ids 포함)
```

**실패 시 rollback**: MVP 는 부분 실패 그대로 두고 노출. 수동 cleanup → `manual_rollback_project.sh <slug>` (GitHub repo·ruleset 삭제, Route53 레코드 삭제, site_manager 의 site 삭제, DB 삭제, 디렉토리 삭제). 자동 rollback 은 future.

### 5.3 dev_deploy (명시적 트리거 only — 결정 14)

`dev_deploy` 는 두 가지 출발점만:
- **직원**: task 상세에서 [개발 화면에 반영하기] (`payload.task_id` = 그 task)
- **관리자**: 프로젝트 상세 또는 reviews 페이지에서 [지금 dev 동기화] (`payload.task_id` = null)

dev 브랜치의 자동 polling/cron/webhook 동기화는 **없다**. 직원에게 "내가 누르니 dev 가 갱신됨" 이라는 인과를 명시적으로 만든다.

```
[직원] POST /api/tasks/<id>/dev-deploy
  PHP 게이트:
    - 본인 task + project_access 보유
    - tasks.status ∈ {drafting, dev_ready, changes_requested, failed, review_requested}
      (review_requested 에서도 재반영 가능 → 재반영 시 'review_requested' 유지하되 last_dev_commit 갱신)
    - 이 task in-flight job 없음
    - 프로젝트의 in-flight prod_deploy job 없음
    - last_dev_deploy_at > 10초 전 (rate limit)

[관리자] POST /admin/api/projects/<slug>/dev-sync  → payload.task_id = null
  PHP 게이트:
    - role='admin'
    - 프로젝트 in-flight job 없음

공통:
  INSERT jobs(type='dev_deploy', task_id?, payload={project_id, branch:'dev'})
  if task_id: UPDATE tasks status='dev_deploying', current_job_id
  file marker + audit_log
  ↓ 200 + job_id
UI 폴링 GET /api/jobs/<id> @ 1Hz

worker → job_dev_deploy.sh
  sudo -u ec2-user bash -c "
    cd /root/<slug>-dev
    git fetch origin dev
    HEAD_BEFORE=\$(git rev-parse HEAD)
    git pull --ff-only origin dev
    HEAD_AFTER=\$(git rev-parse HEAD)
    "
  result: {commit_before, commit_after, url}
  UPDATE projects SET last_synced_commit=HEAD_AFTER, last_synced_at=NOW()
  if task_id:
    UPDATE tasks SET status='dev_ready', last_dev_commit=HEAD_AFTER, last_dev_deploy_at=NOW()
```

**ff-only**: dev 브랜치는 직원이 푸시한 그대로. ff 실패 → job failed (수동 개입). 자동 rebase/reset 위험하므로 X.

**task_id 없는 dev_deploy 의 효과**: projects.last_synced_commit/at 만 갱신. 어떤 task 도 영향 안 받음. 관리자가 dev 서버 화면을 최신 상태로 보고 싶을 때 사용.

**가이드 안내**: "/guide/start" 6단계에 "GitHub 에 push 한 직후 [개발 화면에 반영하기] 를 누르세요. 자동으로 반영되지 않습니다." 굵게 강조.

### 5.4 prod_deploy (관리자 only, **프로젝트 단위** — 결정 13)

```
관리자 /admin/reviews 또는 /admin/projects/<slug> 에서 [현재 dev 를 운영으로]
 → 확인 모달: "이 프로젝트의 현재 dev (commit abc1234) 를 운영에 반영합니다.
              포함되는 작업 N건: '수업 탭 개선'(직원A), '신청폼 추가'(직원B)"
 → POST /admin/api/projects/<slug>/promote-to-prod
   body: {dev_commit_sha: <admin 이 본 dev HEAD>}    -- optimistic concurrency
   PHP 게이트:
     - role='admin'
     - projects.last_synced_commit == body.dev_commit_sha (그 사이 dev 갱신 시 reject)
     - 프로젝트 in-flight job 없음
     - SELECT COUNT(*) FROM tasks WHERE project_id=X AND status='review_requested' > 0
   트랜잭션:
     INSERT jobs(type='prod_deploy', payload={project_id, dev_commit_sha})
     SELECT id FROM tasks WHERE project_id=X AND status='review_requested' FOR UPDATE
       → 그 ID 들을 jobs.payload.task_ids 에 저장 (worker 가 transition 대상으로 알아야 함)
     UPDATE tasks SET status='approved' WHERE id IN (...) (잠금)
     UPDATE tasks SET status='prod_deploying', approved_by=admin, approved_at=NOW() WHERE id IN (...)
   file marker + audit_log

worker → job_prod_deploy.sh
  sudo -u ec2-user bash -c "
    cd /root/<slug>-dev
    git fetch origin
    git checkout main
    git pull --ff-only origin main
    git merge --ff-only <dev_commit_sha>     # main..dev_commit_sha 가 직선이어야 ff
    git push origin main
    git checkout dev                         # dev 복귀 (메모리 [[deploy-flow]] 패턴)
    cd /root/<slug>-prod
    git pull --ff-only origin main
    HEAD=\$(git rev-parse HEAD)
    "
  result: {prod_commit, prod_url, transitioned_task_ids: payload.task_ids}
  트랜잭션:
    UPDATE projects SET last_prod_commit=$HEAD, last_prod_deployed_at=NOW()
    UPDATE tasks SET status='prod_done', last_prod_commit=$HEAD, prod_deployed_at=NOW()
      WHERE id IN payload.task_ids
```

**왜 프로젝트 단위?** 결정 7 (공유 dev) 의 자연스러운 결과. dev 에 직원 A·B·C 의 commit 이 섞여 있으면 운영도 그 묶음 단위로 가는 게 정합. task 별로 cherry-pick 하면 다른 task 의 dependency commit 이 빠져 빌드·동작이 깨질 수 있다. 관리자는 "현재 dev 전체" 를 본 뒤 승인.

**ff-only on main merge**: 머지 커밋 X. dev 가 main 보다 앞서 있어야만 동작. 비-ff (dev 가 옛 main 기반이거나 main 에 별도 hotfix 가 있는 경우) 는 job failed → admin 수동 처리.

**optimistic concurrency**: admin 이 confirm 모달에서 본 `dev_commit_sha` 가 promote 시점 projects.last_synced_commit 와 다르면 reject → admin 이 reload 해서 다시 본 다음 결정. dev_sync 와 promote 사이에 직원이 push 하는 race 차단.

**partial fail**: worker 가 main push 까지는 성공했는데 prod git pull 에서 실패 → job failed. main 은 이미 새 commit 보유 (GitHub 에선). 다음 prod_deploy 시도가 ff 로 처리 가능. tasks 의 status 는 prod_deploying 으로 stuck → admin 대시보드 stuck 경고 → 수동 처리 (UPDATE prod_done 또는 failed 복구 스크립트).

### 5.5 site_create, user_repo_grant

- **site_create**: 기존 프로젝트에 보조 서브도메인 추가. site_manager + Route53 만. (1차 MVP 에선 거의 안 씀)
- **user_repo_grant**: 신규 사용자 등록 후 본인의 모든 project_access 에 collaborator add 일괄.

### 5.6 동시성·race

| 시나리오 | 처리 |
|---|---|
| 같은 task 에 dev_deploy 중복 클릭 | 두 번째 요청은 "in-flight job 있음" 으로 reject |
| 직원 A dev_deploy 와 B dev push 가 겹침 | A 의 pull 이 B 변경까지 같이 가져옴 (이게 공유 dev 의 정합). UI 안내 "여러분의 [반영하기] 는 다른 동료 작업도 함께 dev 에 올림" |
| admin promote 와 직원 dev push 가 겹침 | optimistic concurrency 로 reject. admin 이 reload 후 새 dev HEAD 보고 다시 결정 |
| dev_deploy 와 prod_deploy 가 동시에 in-flight | flock + 프로젝트 in-flight 게이트로 직렬화 (한 프로젝트 한 번에 1 job) |
| prod_deploy 가 main push 까지만 성공하고 prod pull 실패 | partial fail. tasks stuck → admin 수동 cleanup. main 은 이미 갱신됨이라 재시도 ff |
| ff 실패 (main 에 hotfix 가 있거나 dev 가 옛 main 기반) | job failed. admin 이 GitHub 에서 dev rebase 또는 직접 머지 |
| job 30분 초과 | `started_at < NOW() - 30min AND status='running'` 행 → 대시보드 stuck 경고 → 관리자 수동 cleanup |

### 5.7 자기 자신 배포 (bootstrap)

developers.soritune.com 자체는 이 시스템으로 배포하지 않음. junior/pt 처럼 사용자가 직접 git pull. cron `auto-deploy.sh` 매분 pull 패턴 동일 적용.

## 6. 보안 & 운영 원칙

### 6.1 인증

- bcrypt(cost=10), PHP session file handler, cookie `httponly+secure+samesite=Lax`
- 5회 실패 → 15분 잠금
- 비번 정책: 12자 이상 영숫자 혼합
- 임시비번 → 첫 로그인 강제 변경 (`must_change_password`)
- 세션 8시간 idle timeout

### 6.2 권한 게이트

| API 카테고리 | 게이트 |
|---|---|
| `/api/*` | `requireAuth()` + per-endpoint `requireProjectAccess()` |
| `/admin/api/*` | `requireAdmin()` |
| task 조작 | `task.user_id === me \|\| isAdmin()` |

서버측만 진실. 클라이언트 hide 는 UX 목적.

### 6.3 운영 invariant

1. **prod_deploy enqueue 는 admin only** — API + DB 게이트
2. **prod_deploy 는 프로젝트 단위** — task 별 cherry-pick 자동화 X (결정 13)
3. **prod 머지는 main ← dev_commit_sha ff-only** — non-ff 자동화 X
4. **optimistic concurrency** — admin 이 본 dev HEAD 와 promote 시점 last_synced_commit 가 다르면 reject
5. **`status='prod_done'` 천이는 worker success 만**, 일괄 transition 은 payload.task_ids 안의 row 한정
6. **삭제 없음** — archive/cancel 만 (audit 보존)
7. **working tree 권한 = ec2-user:apache 2775/664 setgid** (결정 15), worker 는 `sudo -u ec2-user` 로 git — 메모리 [[root-owned-files-in-ec2user-dirs]] 함정 회피
8. **GitHub branch protection 자동 등록** — project_init 의 ruleset 단계 실패 시 job failed (보안 invariant 차단)
9. **dev 자동 동기화 없음** (결정 14) — cron/webhook 의 자동 git pull 경로 0개

### 6.4 입력·출력 안전

- SQL: PDO prepared (junior 패턴)
- XSS: `e($v ?? '')` ([[php-e-null-strict-signature]])
- CSRF: 모든 write 에 token (세션 + form/header)
- rate limit: 로그인 5/15min, dev_deploy 같은 task 10초 cooldown
- path traversal: slug regex `^[a-z][a-z0-9-]{1,38}$` 강제

### 6.5 GitHub App / Route53 권한 최소화

**GitHub App permissions**:
- Repository: `Administration: read/write` (repo+ruleset 생성), `Contents: read/write` (clone/push), `Metadata: read`
- Organization: `Members: read` (collaborator 추가 검증)
- installation: 사용자 본인 org/계정만 1개

**Branch ruleset 자동 등록** (project_init 시 3개, 결정 16):

| ruleset | target | rules |
|---|---|---|
| main-protected | `refs/heads/main` | block direct push (bypass: org/repo admin = 관리자만), block deletion, block force-push |
| dev-protected | `refs/heads/dev` | block deletion, block force-push (push 자체는 허용) |
| branch-restriction | `refs/heads/**/*` (all refs) | require ref name pattern `^(main\|dev)$` — main/dev 외 branch 생성 차단 |

직원 collaborator role = `push`. branch ruleset 으로 main 직접 push·새 브랜치 생성을 GitHub 단에서 차단. 즉 GitHub App (관리자 권한) 만 main 에 push 가능 — admin 의 prod_deploy job 이 그 경로.

**시크릿 저장**:
- private key: 디스크 권한 + 메모리 로드 후 close
- installation token 1h 만료 안 캐싱 (매 job 새로 발급)

**Route53**:
- IAM: `route53:ChangeResourceRecordSets`, `route53:ListHostedZones` only
- 특정 hosted zone `soritune.com` 1개

### 6.6 개인정보

- `project_init` 의 DEV DB 는 **빈 스키마만** 생성. PROD→DEV 자동 복사 절대 X
- 1차 파일럿은 PII 없는 신규 프로젝트
- 향후 PII 있는 프로젝트 편입 시 **별도 정책 필요** (수동·마스킹 데이터만)

### 6.7 감사

- 로그인 성공·실패·잠금
- 프로젝트 생성, 접근 부여/회수
- task 검토 승인, 운영 반영
- jobs 의 enqueue·전이 (worker 가 audit_log 도 함께)
- secret 마스킹 메타데이터 조회

audit_log 는 append-only.

## 7. 디자인 시스템

### 7.1 토큰

Notion DESIGN.md 토큰을 거의 그대로 채택. 폰트만 Pretendard (한글). 한글 hero/display 의 letter-spacing 은 -1px 까지 약화.

### 7.2 매핑

| Notion | 여기서의 쓰임 |
|---|---|
| `button-primary` (purple) | 화면당 핵심 CTA 1개: "개발 화면에 반영하기" / "운영 반영 승인" |
| navy hero band | 직원 홈 + 관리자 대시보드 상단만 |
| pastel feature cards | `projects.card_tint` 으로 프로젝트별 톤 고정 (시각 식별) |
| `card-feature-yellow-bold` | "검토 대기 N건" 같은 high-emphasis |
| 8px button / 12px card / `rounded.full` badge | 그대로 |
| workspace mockup card | 시작 가이드의 Claude Code mockup 1회 |

### 7.3 상태 → 배지

| task.status | 배지 색 |
|---|---|
| drafting, on_hold | gray (`card-tint-gray`) |
| dev_pending, dev_deploying, prod_deploying | peach |
| dev_ready | mint |
| review_requested | lavender |
| changes_requested | orange (`brand-orange`) |
| approved | purple (`primary`) |
| prod_done | mint (강조) |
| failed | error red (`semantic-error`) |

### 7.4 한국어 라벨 (§13 기술 표현 최소화)

| 내부 | 직원 화면 | 관리자 화면 |
|---|---|---|
| dev_deploy (task) | "개발 화면에 반영하기" | "개발 배포" |
| dev_deploy (project) | (직원이 호출 안 함) | "지금 dev 동기화" |
| prod_deploy | (숨김) | "현재 dev 를 운영으로", "운영 반영 검토" |
| commit | "변경사항" | "변경사항 (`abc1234`)" |
| branch / PR | (숨김) | "브랜치" / "PR" |
| job | "진행 상황" | "작업 큐" |
| review_requested | "검토 요청됨" | "검토 요청 task" (프로젝트 카드 안의 list) |

### 7.5 모바일

- 직원 홈/작업상세: 1024px 이하 1열
- 관리자: 데스크탑 우선
- CTA height ≥ 44px

### 7.6 미적용

- 다크 모드 (future)
- 일러스트 자산 (Notion 의 dots/wires 대신 단순 SVG 패턴)
- 애니메이션은 `transition: 150ms ease` 표준만

## 8. MVP 구현 순서 (제안)

| # | 단계 | 산출물 | 의존 |
|---|---|---|---|
| P0 | 사전 셋업 (사용자가 별도) | GitHub App 등록, Route53 IAM 발급, Pretendard 폰트, .env 채움, **최초 admin 계정 1개 DB 직접 INSERT** (seed_admin.sql) | — |
| P1 | DB 스키마 + 마이그레이션 | 6개 테이블 생성 | P0 |
| P2 | 인증 + 사용자 CRUD | 로그인, /me, admin /users | P1 |
| P3 | 프로젝트 CRUD + 접근권한 (UI 만) | /admin/projects 목록·상세 (수동 register: 기존 프로젝트 등록만) | P2 |
| P4 | jobs 인프라 | DB row + file marker + worker bash 골격 + log streaming | P1 |
| P5 | dev_deploy 종단 | 직원 task → enqueue → worker git pull → tasks.status 천이 + projects.last_synced_commit. admin "지금 dev 동기화" (task_id=null) 도 P5 안에서 | P3, P4 |
| P6 | 프로젝트 단위 prod_deploy + 검토 요청 | task.request-review API, /admin/reviews (프로젝트 카드), promote-to-prod 트랜잭션 + 일괄 task transition + optimistic concurrency | P5 |
| P7 | project_init (마법사) | repo+Route53+site_manager 통합 | P4 |
| P8 | 가이드 + 디자인 폴리시 | /guide/start, Notion-style 시스템 적용, 배지·CTA 통일 | P5 |
| P9 | 감사 + rate limit + invariant 강화 | audit_log 전 영역, DB 트리거(prod_done) | P5, P6 |

→ P1~P6 이 "쓸 수 있는 MVP". P7 이 가장 큰 자동화 가치. P8 은 UX 폴리시. P9 는 안전 폴리시.

## 9. 추후 확장 (스펙 §12 추후)

- 배포 로그 실시간 (현재는 1Hz polling, SSE/Websocket future)
- 실패 시 자동 rollback (현재 수동 script)
- task 댓글 스레드
- 화면 캡처 첨부
- AI 변경사항 요약·위험도 분석
- 직원 PC 데스크톱 도우미
- 작업별 dev 서버 분리 (현재 공유 dev)
- 팀 리더 중간 권한
- 기존 junior/pt/boot/routines/bandsustain 의 편입 (PII 정책 필요)

## 10. 검증·테스트 전략 (writing-plans 단계로)

- 단위: PHP 함수 (e()/jsonSuccess()/권한 게이트) — phpunit
- 통합: jobs worker + sandbox repo + 가짜 Route53 mock
- 종단 (manual smoke 11~16TC): 신규 user 등록 → 프로젝트 생성 마법사 → repo·도메인·DB 확인 → task 등록 → dev_deploy → review → prod_deploy → 운영 URL HTTP 200

writing-plans 스킬로 넘어가서 P1~P9 의 task breakdown 작성.

## 11. 관련 메모리 (관성 회피)

- [[junior-finance-camp-admin-student-manage-wip]] — junior 어드민 패턴 (인증·jsonSuccess)
- [[bandsustain-manual-prod-deploy]] — deploy_history jobId 패턴
- [[php-jsonsuccess-flatten-shape]] — `r.data.<key>` 평탄화
- [[php-e-null-strict-signature]] — e() 가드
- [[php-require-silent-fatal]] — require_once
- [[ec2user-ssh-for-nextjs-git]] — SSH key
- [[root-owned-files-in-ec2user-dirs]] — worker sudo -u
- [[selinux-upload]] — vhost 셋업 시 SELinux 컨텍스트
- [[letsencrypt-webroot-documentroot-match]] — certbot
- [[deploy-flow]] — dev push 후 사용자 확인 게이트
- [[subagent-destructive-db-ops]] — SDD 마이그 가드
