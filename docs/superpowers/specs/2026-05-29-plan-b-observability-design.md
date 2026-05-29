# Plan B — 관측(읽기 전용) 대시보드 설계서

> 2026-05-29. developers.soritune.com Plan A(Foundation) 완료 후속.
> 원본 spec(`2026-05-28-developers-soritune-design.md`)의 Plan B 구상(능동 dev/prod deploy)을
> **현실에 맞춰 축소·재설계**한 문서. 이 문서가 Plan B 구현의 단일 기준이다.

## 0. 배경 — 왜 원본 Plan B 구상에서 바뀌었나

원본 spec §5.3–5.4 는 포털이 `dev_deploy`/`prod_deploy` job 으로 **능동적으로 git pull/merge** 하고,
working tree 가 `ec2-user:apache` 소유이며 worker 가 `sudo -u ec2-user git` 으로 동작한다고 가정했다.

**현실 점검 결과 (2026-05-29):**
- junior 는 이미 `/root/auto-deploy.sh` 가 **매분 root cron 으로** dev/prod 를 자동 `git pull` 중이다.
  → 포털이 또 pull 하면 중복·충돌.
- junior dev/prod 디렉토리는 `apache:apache` 소유다 (spec 가정인 `ec2-user:apache` 아님).
  → ec2-user 는 그 repo 에 쓰기 불가, 포털(apache)은 **읽기 git 명령을 sudo 없이 바로** 실행 가능.
- (별개 발견) auto-deploy.sh 의 junior dev 경로가 폐기된 옛 경로(`_______site_SORITUNECOM_JUNIOR`)를
  가리킨다. **이 문서 범위 밖**(별도 처리). 여기서는 손대지 않는다.

**결정 (사용자 합의):**
junior 같은 **기존 자동배포 프로젝트는 그대로 두고**, 포털은 각 프로젝트의 git/사이트 상태를
**읽어서 보여주는 관측 대시보드**가 된다. 능동적 dev_deploy/prod_deploy(git pull/merge)는
포털이 **직접 만든 신규 프로젝트(Plan C)에만** 나중에 적용한다.

이로써 Plan B 의 위험은 사실상 0: **git 쓰기 0, DB 쓰기 0, sudo 불필요, 운영 무중단.**

## 1. 범위

**포함 (Plan B):**
- 프로젝트별 git 상태 읽기: dev/prod 현재 HEAD(short SHA + subject/author/date) + `git log --oneline -10`
- 미배포 diff: dev 가 prod(main)보다 앞선 커밋 수·목록 (`main..dev`)
- 사이트 up/down: dev/prod 서브도메인 HTTP 응답 코드
- 배포 로그 tail: 프로젝트별 deploy.log 경로가 설정된 경우 마지막 N줄 (선택)
- 위를 묶어 보여주는 관리자 프로젝트 상세 화면

**제외 (다른 Plan):**
- 능동 dev_deploy / prod_deploy (git pull/merge/push) — Plan C 신규 프로젝트 + 별도 설계
- tasks 테이블 UI/상태머신 — Plan C/이후
- GitHub App / Route53 / project_init 마법사 — Plan C
- junior auto-deploy.sh 경로 버그 수정 — 별도 작업

## 2. 아키텍처

```
admin/projects.php (카드 목록)
   │  카드에 [상세] 링크 추가
   ▼
admin/project_detail.php?id=<pid>      (PHP: requireAdmin, projects row 로드, 셸 렌더)
   │  JS fetch (읽기 전용, CSRF 불요)
   ▼
api/system.php?action=project_status&op=get&id=<pid>
   │  라우터: requireAdmin() → require_once api/system/project_status.php
   ▼
project_status.php (핸들러)
   ├─ lib/GitInspector::inspect(dev_dir)   → dev HEAD/log
   ├─ lib/GitInspector::inspect(prod_dir)  → prod HEAD/log
   ├─ lib/GitInspector::countAhead(dev_dir,'main','dev') → 미배포 수+목록
   ├─ lib/SiteCheck::ping(dev_subdomain) / ping(prod_subdomain)
   └─ (deploy_log_path 있으면) tail
   → jsonSuccess([...])   (switch 각 case `return;`)
```

### 컴포넌트 책임

**`lib/GitInspector.php`** (PSR-4 `Soritune\Developers\GitInspector`)
- 한 디렉토리의 git 상태를 **읽기만** 한다. 절대 쓰기 명령(pull/fetch/merge/checkout/reset/push) 호출 금지.
- 모든 호출은 `git -C <dir> -c safe.directory=<dir> <readonly-subcommand> ...` 형태.
  - `-c safe.directory=<dir>` 로 dubious-ownership 에러를 항상 회피.
- 인자는 고정 화이트리스트 + `escapeshellarg`. 외부 입력은 검증된 절대경로(dir)뿐.
- 메서드:
  - `inspect(string $dir): array`
    반환: `{ok:bool, error?:string, head?:string, subject?:string, author?:string, date?:string, branch?:string, log?:[{sha,subject,date}, …≤10]}`
    - dir 없음 → `{ok:false, error:'경로 없음'}`
    - `.git` 없음 → `{ok:false, error:'git 저장소 아님'}`
  - `countAhead(string $devDir, string $base, string $head): array`
    반환: `{ok:bool, count?:int, commits?:[{sha,subject}, …], error?:string}`
    - `rev-list --count <base>..<head>` 와 `log --oneline <base>..<head>`.
    - base ref 가 로컬에 없으면 `origin/<base>` 폴백, 그것도 없으면 `{ok:false, error:'비교 불가'}`.
    - **dev_dir 기준으로 계산**(dev/main 양쪽 ref 가 있을 가능성이 가장 높은 곳).

**`lib/SiteCheck.php`** (PSR-4 `Soritune\Developers\SiteCheck`)
- `ping(string $host): array` → `{up:bool, code?:int}`. HTTP(S) HEAD, 5초 타임아웃.
- 타임아웃/DNS 실패 → `{up:false}` ("다운"이 아니라 "응답 없음"으로 표기). 리다이렉트는 따라가지 않음(코드 그대로).

**`api/system/project_status.php`** (핸들러; 라우터가 이미 requireAdmin)
- `op=get`, `id` 필수. projects row 없으면 `jsonError('not found',404)`.
- 위 컴포넌트 묶어 `jsonSuccess(['dev'=>…,'prod'=>…,'undeployed'=>…,'sites'=>…,'log'=>…])`.
- 읽기 전용이라 POST/CSRF 없음. switch 각 case 끝 `return;` (test-mode fallthrough 가드).
- 한 컴포넌트 실패가 전체를 깨지 않음: 각 필드 독립, 실패 시 해당 필드만 error shape.

**`admin/project_detail.php`** (UI)
- requireAdmin, `?id` 로 projects row 로드(없으면 목록으로 redirect).
- JS 가 status API fetch 후 렌더. 모든 동적 값 `escape()`(single-quote 포함, 기존 패턴).
- 섹션: 프로젝트 메타(slug/repo/디렉토리) · dev 카드(HEAD+로그+up/down) · prod 카드 · 미배포 diff · deploy.log tail.
- 한 섹션 데이터가 error여도 다른 섹션은 정상 렌더.

## 3. 데이터 모델 변경

`projects` 테이블에 **선택적 컬럼 1개** 추가 (migration 008):
```sql
ALTER TABLE projects ADD COLUMN deploy_log_path VARCHAR(255) NULL DEFAULT NULL AFTER prod_dir;
```
- deploy.log tail 표시용. NULL 이면 로그 섹션 "미설정". 등록/수정 UI 에서 입력(이번 범위에선 선택; 비워도 됨).
- 마이그레이션은 `scripts/run_migrations.sh` 로 적용(멱등). 기존 `last_synced_commit` 등 컬럼은 그대로 두되 이 Plan 에선 안 씀(능동 배포가 채우는 값).

## 4. 보안 / 안전

- **git 쓰기 0**: GitInspector 는 read-only subcommand 화이트리스트만. 코드리뷰에서 pull/fetch/merge/checkout/push/reset 부재 확인.
- **인젝션 0**: 모든 인자 `escapeshellarg`, 동적 부분은 검증된 절대경로(dir)뿐. ref 이름(main/dev/origin/main)은 고정 리터럴.
- **경로**: `dev_dir`/`prod_dir` 는 프로젝트 등록 시 들어온 값. status 조회 전 `is_dir` 확인.
- **권한 게이트**: 라우터 `requireAdmin()`. 상세 화면도 `requireAdmin()`.
- **DB 쓰기 0** (migration 008 의 1회 ALTER 제외).
- **출력**: `e()` / JS `escape()`. SQL 은 PDO prepared(projects 조회).

## 5. 테스트 전략

- **GitInspectorTest** (unit): 테스트 내부에서 임시 git repo 생성(`git init`+더미 커밋 2개+dev 브랜치), inspect/countAhead 검증. 없는 경로·git 아닌 경로·ahead 카운트 케이스. teardown 으로 임시 dir 삭제. **실제 프로젝트 디렉토리 비접촉.**
- **SiteCheckTest** (unit): up = `https://developers.soritune.com`(자기, 200), down = 존재하지 않는 호스트(짧은 타임아웃).
- **ProjectStatusApiTest** (integration): UserApiTest 패턴(직접 핸들러 require + 세션/`extractFirstJson`). 임시 git repo dir 로 픽스처 프로젝트 등록 → status 조회 → 필드 존재 검증 → teardown 정리.
- **검증 게이트**: ① phpunit 전체 green ② binnie 로그인 → junior 상세 진입 → 실제 dev/prod commit·로그·미배포·up/down 표시 확인(읽기만) ③ git 호출 인젝션 표면 코드리뷰.

## 6. 구현 방식

subagent-driven-development. task 단위(GitInspector → SiteCheck → API+라우터 → 상세 UI → 통합테스트)
구현→spec리뷰→코드리뷰→수정→커밋. developers 는 단일 환경(main 직접 커밋)이지만 전부 읽기 전용이라
운영 위험 최소. 커밋마다 `?v=` 캐시버스터 동반(.htaccess CSS 1일 캐시).

## 7. 비목표 (명시적 제외)

- 능동 배포(pull/merge/push) — Plan C
- tasks 상태머신 — Plan C
- junior auto-deploy.sh 경로 버그 — 별도
- 실시간 자동 새로고침/폴링 — 이번엔 진입 시 1회 fetch + 수동 새로고침(필요 시 후속)
