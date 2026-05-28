# developers.soritune.com

소리튠 사내 AI 개발 협업·배포 관리 도구. 비개발자 직원이 Claude Code/Codex 로 만든 미니 프로젝트의 dev/prod 배포를 관리한다.

스펙: `docs/superpowers/specs/2026-05-28-developers-soritune-design.md`

## 개발
```
composer install
./scripts/run_migrations.sh
vendor/bin/phpunit
./tests/run.sh
```
