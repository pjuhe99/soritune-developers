# developers.soritune.com

소리튠 사내 AI 개발 협업·배포 관리 도구. 비개발자 직원이 Claude Code/Codex 로 만든 미니 프로젝트의 dev/prod 배포를 관리한다.

스펙: `docs/superpowers/specs/2026-05-28-developers-soritune-design.md`

## 개발
```
composer install
./scripts/run_migrations.sh   # Task 2에서 추가
vendor/bin/phpunit
./tests/run.sh                # Task 17에서 추가
```

> `scripts/run_migrations.sh` 와 `tests/run.sh` 는 아직 존재하지 않습니다. 각각 해당 태스크에서 생성됩니다.
