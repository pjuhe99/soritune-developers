# Plan C — project_init 마법사 (신규 프로젝트 자동 생성) 설계서

> 2026-05-29. developers.soritune.com Plan A(Foundation) + Plan B(관측) 완료 후속.
> 원본 spec(`2026-05-28-developers-soritune-design.md`) §5.2 의 project_init 구상을
> **현실(GitHub App 없음, 새 개인계정 PAT, 기존 site_manager 파일큐 존재)에 맞춰 확정**한 문서.
> 이 문서가 Plan C 구현의 단일 기준이다.

## 0. 배경 — 현실 점검 (2026-05-29)

| 자원 | 상태 |
|---|---|
| `gh` CLI | ✅ 설치됨 (`/usr/bin/gh`) |
| `aws` CLI | ✅ 설치됨 (Route53 가능, soritune.com = Route53 호스티드존) |
| `certbot` | ✅ 설치됨 |
| `site_manager.sh` + 파일큐 worker | ✅ 존재 — 기존 app.soritune.com 포털이 쓰는 검증된 패턴 |
| GitHub App | ❌ 없음 (keys/, .env GITHUB 자격 없음) |
| 기존 서버 GitHub 자격 | pjuhe99 SSH 키들 (routines/bandsustain/junior) — **Plan C 와 다른 계정** |
| 서버 공인 IP | `3.37.213.224` (Route53 A레코드 타겟) |

**확정된 결정 (사용자 합의):**
1. repo 는 **기존 pjuhe99 와 다른 새 개인 계정(user)** 에 생성, 그 계정 하나로 고정.
2. 자격증명 = 그 계정의 **PAT 하나** (`.env GITHUB_TOKEN`). repo 생성·collaborator·ruleset(API) + clone(HTTPS) 모두 이 PAT 로. SSH 키 별도 생성 안 함.
3. 마법사는 **전체 자동화**: repo + Route53 + 사이트(vhost/certbot/빈DB) + clone 을 단일 `project_init` job 으로.
4. root 작업(vhost/certbot/DB)은 **기존 site_manager 파일큐 + 기존 root cron 에 위임** — 새 root 권한/sudoers/cron 0.
5. collaborator(멤버) 자동 초대 포함.
6. 부분실패: 노출 + 수동 롤백 스크립트(`manual_rollback_project.sh`), 자동 롤백은 future.
7. 모든 단계 멱등 (재실행 안전).

## 1. 범위

**포함 (Plan C):**
- 관리자 "새 프로젝트" 마법사 폼 (slug/name/desc/dev_subdomain/prod_subdomain/members)
- 단일 `project_init` job: GitHub repo(+dev 브랜치+ruleset 3개+collaborator) → Route53 A레코드×2 → site_manager dev/prod(vhost+certbot+빈DB) → git clone×2 → 권한설정 → projects active
- 부분실패 result 누적 + 대시보드 stuck 경고 + 수동 롤백 스크립트

**제외 (비목표):**
- 자동 롤백 (수동 스크립트만)
- organization 계정 (코드에 user|org 분기 여지만, user 만 검증)
- DNS-01 인증서 (certbot http-01 webroot 만)
- site_create (기존 프로젝트 보조 서브도메인)
- tasks 상태머신 / 능동 dev·prod deploy (별도 Plan)
- 위험한 외부생성의 자동 e2e 테스트 (수동 검증만)

## 2. 아키텍처

```
admin/projects.php [새 프로젝트] 폼
  → POST /api/system.php?action=projects&op=init
     PHP 게이트(projects.php 핸들러 확장): requireAdmin + CSRF
       + Validation: slug, dev/prod subdomain, members 존재
       + 중복: slug UNIQUE, subdomain 충돌
     → 트랜잭션: projects row (status='provisioning') + JobQueue::enqueue('project_init', payload)
       payload = {project_id, slug, name, description, dev_subdomain, prod_subdomain, member_ids:[...]}
     → 200 + job_id
  → developers worker (apache, Plan A 의 developers_worker.sh) 가 project_init 픽업
     → scripts/job_project_init.sh <job_id> (apache 권한)
        오케스트레이터: 아래 §4 순서대로 각 단계 PHP/CLI 호출, result JSON 누적
  → UI 폴링 GET /api/system.php?action=jobs&op=list (Plan A jobs 화면)
```

**권한 경계 — 새 root 권한 0:**
- 포털 worker = apache (Plan A 그대로). GitHub/Route53/clone 은 apache 로 충분.
- root 필요 작업(vhost/certbot/DB) = 기존 site_manager 파일큐(`_______site_SORITUNECOM_APP/jobs/pending`)에 JSON 떨구고 기존 root cron 이 처리, done 폴링.

## 3. 컴포넌트 (단일 책임)

| 파일 | 책임 |
|---|---|
| `lib/GithubAdmin.php` | PAT 로 repo 생성/dev브랜치/ruleset/collaborator. 주입 가능한 `runner` 클로저(테스트 mock). |
| `lib/Route53.php` | `aws route53 change-resource-record-sets` UPSERT A레코드. runner 주입. |
| `lib/SiteManagerClient.php` | site_manager 파일큐에 JSON enqueue + done 폴링/파싱. 큐 경로 주입(테스트). |
| `scripts/job_project_init.sh` | 얇은 오케스트레이터: job 읽기 → 단계 순서 호출 → result 누적 → projects active/failed. |
| `scripts/manual_rollback_project.sh` | 부분실패 수동 정리(repo·ruleset·DNS·site·DB·dir 역순, best-effort). |
| `public_html/api/system/projects.php` | `op=init` 추가 (게이트 + enqueue). 기존 핸들러 확장. |
| `public_html/admin/projects.php` | "새 프로젝트" 마법사 폼 추가 (기존 등록폼 대체/확장). |

### 3.1 GithubAdmin (계정 = user, PAT)

`.env`: `GITHUB_TOKEN`, `GITHUB_ACCOUNT=<username>`, `GITHUB_ACCOUNT_TYPE=user` (org 분기 여지).
모든 호출은 `GH_TOKEN=<pat> gh api ...` 또는 동등한 runner. 메서드:

- `createRepo(slug, description): array` — `POST /user/repos` (private, auto_init=true → main 초기커밋).
  **멱등 계약(P1 수정):** 성공 시 `{ok:true, repo_url, full_name, created:true}`.
  **이미 존재하면 422(name exists) 를 받아 `GET /repos/<acct>/<slug>` 로 full_name/repo_url 을 복구해 `{ok:true, repo_url, full_name, created:false}` 반환** — 부분실패 재실행이 1단계에서 막히지 않도록. (단 그 repo 가 우리 계정 소유가 아니면 `{ok:false, error:'repo name taken by other owner'}`.)
- `createDevBranch(repo): array` — main HEAD sha 조회 → `POST /repos/<acct>/<repo>/git/refs` (refs/heads/dev). 이미 있으면(422) `{ok:true, existed:true}`.
- `addRulesets(repo): array` — **2개** (P0-B 수정: ref-name 제한 제거 — 직원의 feature/*·fix/* 작업 브랜치 생성을 막지 않기 위해):
  ① main: direct push/force-push/deletion 차단 (repo admin bypass)
  ② dev: force-push/deletion 차단
  (③ ref-name `^(main|dev)$` 제한은 **제거**. 협업 흐름: 직원이 feature 브랜치 자유 생성 → PR 로 dev 머지.)
  **실패 시 전체 job failed** (보안 invariant — 보호 없는 repo 는 active 안 함). 부분등록 시 result.ruleset_ids 에 기록. 재실행 시 이미 있는 ruleset 은 GET 으로 확인 후 skip(멱등).
- `addCollaborators(repo, github_usernames[], role='push'): array` — 각 `PUT /repos/.../collaborators/<user>`. github_username 미설정 멤버 → `skipped[]` 기록(가입 후 user_repo_grant 후처리). collaborator 실패는 job failed 아님(경고).

### 3.2 Route53

- `upsertA(fqdn, ip='3.37.213.224'): array` — soritune.com 존 ID 조회 → `change-resource-record-sets` UPSERT(A, TTL 300). UPSERT 라 멱등. dev_subdomain·prod_subdomain 각각.
- IAM 권한: `route53:ListHostedZones`, `route53:ChangeResourceRecordSets` (최소).

### 3.3 SiteManagerClient

- `provision(subdomain, opts): array` — 한 사이트의 site_manager 액션 시퀀스를 순서대로 enqueue+폴링:
  `check_conflict → create_folders → create_database → create_vhost_http → issue_ssl → create_vhost_ssl`.
  각 액션: `_APP/jobs/pending/<uniqid>.json {action, subdomain, ...}` 떨굼 → 기존 root cron 처리 → `_APP/jobs/done/<uniqid>.json` 폴링(timeout 5분). success 아니면 시퀀스 중단 + 단계 기록.
  - `create_database` 는 **빈 DB만** 생성(스키마 없음) — spec §6.5 "DEV 는 빈 스키마만" 과 일치. PROD→DEV 자동복사 절대 X.
  - **소유권/SELinux (P0-A):** site_manager `create_folders` 가 디렉토리를 **apache:apache** 로 만들고 `.db_credentials`(640)·logs(httpd_log_t semanage+restorecon)·기본 `public_html/index.php`(ROBOTION 랜딩)까지 넣는다. 즉 root 권한 작업(소유권·SELinux)은 **전부 site_manager(기존 root cron) 안에서** 끝난다 → 포털 worker(apache)는 추가 chown/restorecon 불필요. (이것이 "새 root 권한 0" 의 실제 근거.)
- `issue_ssl` 은 DNS 가 서버를 가리킨 뒤라야 http-01 통과 → §4 순서에서 Route53 을 먼저, **DNS 전파 폴링** 후 호출.

## 4. project_init 오케스트레이션 순서 (job_project_init.sh)

```
0. job 읽기, projects row = provisioning
1. GitHub:
   createRepo(멱등: exists→GET 복구) → createDevBranch → addRulesets(2개, 실패=job failed) → addCollaborators(skipped 기록)
2. Route53: upsertA(dev_subdomain) , upsertA(prod_subdomain) → 3.37.213.224 (UPSERT 멱등)
3. DNS 전파 대기: dig/getent 로 fqdn 이 IP 로 풀릴 때까지 짧게 폴링 (timeout 3분).
   ※ 실패판정(P1-openQ): 로컬 해석이 timeout 내 IP 로 안 풀려도 **fatal 로 보지 않고** 다음으로 진행.
     이유 — AWS VPC 리졸버(172.26.0.2)가 옛 NXDOMAIN 을 negative-cache 해서 *로컬* 해석이
     한동안 실패할 수 있으나 certbot http-01(공개망 LE)엔 무관. 실제 실패 판정은 4/5단계의
     issue_ssl 결과로만 한다(issue_ssl 자체에 재시도 N회). 3단계 폴링은 "되도록 기다림"일 뿐.
4. SiteManagerClient::provision(dev_subdomain)   [check_conflict..create_vhost_ssl]
5. SiteManagerClient::provision(prod_subdomain)
6. git clone (PAT HTTPS, credential helper 로 토큰 주입 — URL/로그 노출 X):
   **임시 clone 후 내용 이동(P1 수정 — site_manager 가 public_html 에 기본 index.php 를 이미 넣어
   `git clone <dir>` 는 무조건 실패하므로):**
   - `git clone --branch dev <repo> <tmp_dev>` (빈 임시 디렉토리)
   - site_manager 산출물 보존: `.db_credentials`(SITE_DIR 레벨, public_html 밖이라 영향 없음)는 그대로.
     public_html 의 기본 index.php/README 는 repo 내용으로 대체.
   - `tmp_dev/*` + `tmp_dev/.git` → `dev_dir/public_html/` 로 이동(기존 기본 index.php 덮어씀), tmp 삭제.
     (또는 public_html 을 비우고 tmp 를 public_html 로 rename — 어느 쪽이든 "빈 곳에 clone → 자리 이동".)
   - prod 동일: `--branch main` → `prod_dir/public_html/`.
   - 소유권: 이동 후에도 apache:apache 유지(이동 주체가 apache worker). 필요 시 site_manager 에 재-chown 액션 위임(별도 root 작업 신설 금지 — 가급적 apache 소유로 자연 유지).
7. projects: status='active', dev_dir, prod_dir, dev_db_name, prod_db_name,
   last_synced_commit=<dev HEAD>, last_prod_commit=<main HEAD>, init_job_id
   (이전 spec 의 chown/chmod/restorecon "7단계" 는 **삭제** — site_manager 가 apache:apache+SELinux 를
    이미 처리하므로 포털 worker 의 root 작업 불필요. "새 root 권한 0" 원칙 준수. → P0-A 해소.)
9. result JSON 저장 (각 단계 ok/fail + repo_url, ruleset_ids, skipped_members, urls).
```

**디렉토리/DB 이름 규칙** (기존 사이트 관례):
- dev_dir = `/var/www/html/_______site_SORITUNECOM_DEV_<SLUG_UPPER>`
- prod_dir = `/var/www/html/_______site_SORITUNECOM_<SLUG_UPPER>`
- dev_db_name = `SORITUNECOM_DEV_<SLUG_UPPER>`, prod_db_name = `SORITUNECOM_<SLUG_UPPER>`
(SLUG_UPPER = slug 대문자, 하이픈→언더스코어. 구현 시 site_manager 의 실제 명명 규칙과 대조.)

## 5. 실패 처리

- 각 단계 result 누적: `{repo:ok, dev_branch:ok, rulesets:ok, collaborators:{added:[],skipped:[]}, dns_dev:ok, dns_prod:ok, dev_site:ok, prod_site:FAIL, ...}`.
- 실패 시: `jobs.status='failed'` + `error_message`, projects 는 `provisioning` 에 stuck.
- 대시보드(jobs 화면 + projects 카드): provisioning 에서 멈춘 프로젝트에 "init 실패 — 수동 정리 필요" 경고.
- 정리: `scripts/manual_rollback_project.sh <slug>` — repo·ruleset 삭제(gh), Route53 레코드 삭제(aws), site_manager backup_and_clean(dev/prod), DB drop, dir 삭제, projects archived. 각 단계 best-effort(없으면 skip).
- **멱등 재실행**: 고친 뒤 같은 project_init 재실행 가능 —
  - createRepo: exists→GET 으로 full_name/repo_url 복구해 ok 로 진행 (§3.1 멱등 계약).
  - createDevBranch/addRulesets: 이미 있으면 확인 후 skip.
  - Route53: UPSERT(항상 멱등).
  - site_manager: 각 사이트 시작 시 check_conflict 가 "이미 존재" 보고하면 그 사이트의 provision 시퀀스를 skip(이미 완료된 것으로 간주)하고 다음 단계로.
  - clone(§4 6단계): public_html 에 repo 의 `.git` 이 이미 있으면(=clone 완료) skip; 없으면 임시 clone→이동 재수행.

## 6. 보안 / 운영 invariant

1. **PAT 는 .env 시크릿** (apache:apache 640), settings 화면 마스킹. 로그/URL 에 토큰 노출 금지(git credential helper 사용).
2. **prod 머지·push 자동화 없음** — Plan C 는 생성만. main 직접 push 는 GitHub ruleset 이 차단(PAT=admin 만 가능하나 Plan C 는 repo 생성 직후 손 안 댐).
3. **branch ruleset 2개(main/dev 보호) 자동 등록 실패 시 job failed** (보안 invariant). ref-name 제한은 협업 차단이라 제외(P0-B).
4. **DEV DB = 빈 스키마만**. PROD→DEV 복사 절대 X.
5. **새 root 권한 0** — 소유권(apache:apache)·SELinux·vhost·certbot·DB 등 root 작업은 전부 기존 site_manager 파일큐+root cron 에 위임. 포털 worker(apache)는 GitHub/Route53/clone+파일이동만(chown/restorecon 직접 호출 금지) (P0-A).
6. **신규 프로젝트 소유권 = apache:apache** (junior 등 기존 사이트와 동일, site_manager 가 생성). spec 원안의 `ec2-user:apache` 전제는 폐기.
7. **삭제 없음(포털 DB)** — projects 는 archived. 외부자원 삭제는 manual_rollback 만.
8. **입력 검증**: slug regex `^[a-z][a-z0-9-]{1,38}` + 끝 하이픈 금지(Plan A Validation), subdomain isValidSubdomain, path traversal 차단.
9. **인젝션**: gh/aws/git 호출 인자 escapeshellarg, slug/subdomain 은 검증된 값만.

## 7. 테스트 전략

- **GithubAdminTest** (unit): runner mock 으로 createRepo/createDevBranch/addRulesets/addCollaborators. 케이스: 신규생성(created:true), **repo-exists→GET 복구→created:false (멱등 계약)**, **다른 소유자 이름충돌→ok:false**, ruleset 2개 등록·실패→fail, dev 브랜치 existed, skipped member.
- **Route53Test** (unit): runner mock, UPSERT changeset JSON 형식. 재호출(UPSERT) 멱등 확인.
- **SiteManagerClientTest** (unit): 임시 pending/done 디렉토리로 enqueue→폴링→파싱. success/fail/timeout. **재실행: check_conflict 가 이미존재 보고 시 그 사이트는 skip 로 진행되는지**.
- **op=init API** (integration): ProjectApiTest 패턴. 게이트(requireAdmin/CSRF/검증/중복) + enqueue 됐는지 확인 (**실제 job 실행 안 함** — payload/projects provisioning row 만 검증). teardown 정리.
- **멱등 재실행 (unit, P1-openQ 필수):** 핵심 invariant 가 멱등성이므로 명시 테스트. GithubAdmin/Route53/SiteManagerClient 각 mock 에서 "1차 일부 성공 후 2차 동일 호출" 시퀀스가 (a) 이미 된 단계는 skip/ok, (b) 안 된 단계부터 진행, (c) 최종 동일 결과로 수렴함을 검증. (오케스트레이터 bash 는 단위테스트 어려우므로 이 멱등 계약을 컴포넌트 레벨에서 보장.)
- **수동 e2e**: 실제 테스트 프로젝트 1개 생성 → repo/DNS/사이트/clone 확인 → **일부러 중간 실패 유발 후 같은 init 재실행이 끝까지 진행되는지(멱등 e2e)** → `manual_rollback_project.sh` 로 정리. phpunit 에선 절대 외부생성 안 함(junior 정책).

## 8. 구현 순서 (writing-plans 가 task 분해)

① .env 시크릿(GITHUB_TOKEN/ACCOUNT/TYPE) + Settings 마스킹 표시
② GithubAdmin + 단위테스트
③ Route53 + 단위테스트
④ SiteManagerClient + 단위테스트
⑤ job_project_init.sh 오케스트레이터 (얇게)
⑥ op=init API + 마법사 폼 UI + 통합테스트
⑦ manual_rollback_project.sh
⑧ 수동 e2e (테스트 프로젝트 생성→검증→롤백) + 메모리 기록
