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

- `createRepo(slug, description): array` — `POST /user/repos` (private, auto_init=true → main 초기커밋). 이미 있으면 `{ok:false, error:'repo exists'}` (멱등). 성공 시 `{ok:true, repo_url, full_name}`.
- `createDevBranch(repo): array` — main HEAD sha 조회 → `POST /repos/<acct>/<repo>/git/refs` (refs/heads/dev). 이미 있으면 ok.
- `addRulesets(repo): array` — 결정16 의 3개:
  ① main: direct push/force-push/deletion 차단 (repo admin bypass)
  ② dev: force-push/deletion 차단
  ③ ref-name 제한: 새 ref 생성은 `^(main|dev)$` 만
  **실패 시 전체 job failed** (보안 invariant — 보호 없는 repo 는 active 안 함). 부분등록 시 result.ruleset_ids 에 기록.
- `addCollaborators(repo, github_usernames[], role='push'): array` — 각 `PUT /repos/.../collaborators/<user>`. github_username 미설정 멤버 → `skipped[]` 기록(가입 후 user_repo_grant 후처리). collaborator 실패는 job failed 아님(경고).

### 3.2 Route53

- `upsertA(fqdn, ip='3.37.213.224'): array` — soritune.com 존 ID 조회 → `change-resource-record-sets` UPSERT(A, TTL 300). UPSERT 라 멱등. dev_subdomain·prod_subdomain 각각.
- IAM 권한: `route53:ListHostedZones`, `route53:ChangeResourceRecordSets` (최소).

### 3.3 SiteManagerClient

- `provision(subdomain, opts): array` — 한 사이트의 site_manager 액션 시퀀스를 순서대로 enqueue+폴링:
  `check_conflict → create_folders → create_database → create_vhost_http → issue_ssl → create_vhost_ssl`.
  각 액션: `_APP/jobs/pending/<uniqid>.json {action, subdomain, ...}` 떨굼 → 기존 root cron 처리 → `_APP/jobs/done/<uniqid>.json` 폴링(timeout 5분). success 아니면 시퀀스 중단 + 단계 기록.
  - `create_database` 는 **빈 DB만** 생성(스키마 없음) — spec §6.5 "DEV 는 빈 스키마만" 과 일치. PROD→DEV 자동복사 절대 X.
- `issue_ssl` 은 DNS 가 서버를 가리킨 뒤라야 http-01 통과 → §4 순서에서 Route53 을 먼저, **DNS 전파 폴링** 후 호출.

## 4. project_init 오케스트레이션 순서 (job_project_init.sh)

```
0. job 읽기, projects row = provisioning
1. GitHub:
   createRepo → createDevBranch → addRulesets(실패=job failed) → addCollaborators(skipped 기록)
2. Route53: upsertA(dev_subdomain) , upsertA(prod_subdomain) → 3.37.213.224
3. DNS 전파 대기: dig/getent 로 fqdn 이 IP 로 풀릴 때까지 짧게 폴링 (timeout 3분).
   ※ 메모리: AWS VPC 리졸버(172.26.0.2)가 옛 NXDOMAIN negative-cache 가능 →
     로컬 해석 실패해도 certbot http-01(공개망)엔 무관. 그래도 issue_ssl 에 재시도 둠.
4. SiteManagerClient::provision(dev_subdomain)   [check_conflict..create_vhost_ssl]
5. SiteManagerClient::provision(prod_subdomain)
6. git clone (PAT HTTPS, credential helper 로 토큰 주입 — URL/로그 노출 X):
   dev_dir ← --branch dev , prod_dir ← --branch main
7. 권한: chown -R ec2-user:apache <dir>; find -type d chmod 2775(setgid); -type f 664;
   restorecon -R <dir> (SELinux 기본 컨텍스트). ※ site_manager create_folders 소유권과
   다르면 보정 — 구현 시 create_folders 실제 소유권 확인 후 결정.
8. projects: status='active', dev_dir, prod_dir, dev_db_name, prod_db_name,
   last_synced_commit=<dev HEAD>, last_prod_commit=<main HEAD>, init_job_id
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
- **멱등 재실행**: 고친 뒤 같은 project_init 재실행 가능 — createRepo(exists 체크), Route53(UPSERT), check_conflict(이미 있으면 fail→해당 단계 skip 로직), clone(dir 존재 시 skip).

## 6. 보안 / 운영 invariant

1. **PAT 는 .env 시크릿** (apache:apache 640), settings 화면 마스킹. 로그/URL 에 토큰 노출 금지(git credential helper 사용).
2. **prod 머지·push 자동화 없음** — Plan C 는 생성만. main 직접 push 는 GitHub ruleset 이 차단(PAT=admin 만 가능하나 Plan C 는 repo 생성 직후 손 안 댐).
3. **branch ruleset 자동 등록 실패 시 job failed** (보안 invariant).
4. **DEV DB = 빈 스키마만**. PROD→DEV 복사 절대 X.
5. **새 root 권한 0** — root 작업은 기존 site_manager 파일큐 위임.
6. **삭제 없음(포털 DB)** — projects 는 archived. 외부자원 삭제는 manual_rollback 만.
7. **입력 검증**: slug regex `^[a-z][a-z0-9-]{1,38}` + 끝 하이픈 금지(Plan A Validation), subdomain isValidSubdomain, path traversal 차단.
8. **인젝션**: gh/aws/git 호출 인자 escapeshellarg, slug/subdomain 은 검증된 값만.

## 7. 테스트 전략

- **GithubAdminTest** (unit): runner mock 으로 createRepo/createDevBranch/addRulesets/addCollaborators. repo-exists, ruleset 실패→fail, skipped member.
- **Route53Test** (unit): runner mock, UPSERT changeset JSON 형식.
- **SiteManagerClientTest** (unit): 임시 pending/done 디렉토리로 enqueue→폴링→파싱. success/fail/timeout.
- **op=init API** (integration): ProjectApiTest 패턴. 게이트(requireAdmin/CSRF/검증/중복) + enqueue 됐는지 확인 (**실제 job 실행 안 함** — payload/projects provisioning row 만 검증). teardown 정리.
- **수동 e2e**: 실제 테스트 프로젝트 1개 생성 → repo/DNS/사이트/clone 확인 → `manual_rollback_project.sh` 로 정리. phpunit 에선 절대 외부생성 안 함(junior 정책).

## 8. 구현 순서 (writing-plans 가 task 분해)

① .env 시크릿(GITHUB_TOKEN/ACCOUNT/TYPE) + Settings 마스킹 표시
② GithubAdmin + 단위테스트
③ Route53 + 단위테스트
④ SiteManagerClient + 단위테스트
⑤ job_project_init.sh 오케스트레이터 (얇게)
⑥ op=init API + 마법사 폼 UI + 통합테스트
⑦ manual_rollback_project.sh
⑧ 수동 e2e (테스트 프로젝트 생성→검증→롤백) + 메모리 기록
