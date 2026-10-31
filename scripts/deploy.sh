#!/usr/bin/env bash
# 서버에서 실행하는 배포 스크립트. 순서가 중요하다: 백업 → 새 버전 기동 → 마이그레이션 → 시딩 → 점검.
#
#   scripts/deploy.sh
#   SMOKE_URL=https://api.example.com SMOKE_ORIGIN=https://your-app.vercel.app scripts/deploy.sh
#
# APP_IMAGE가 .env.prod에 있으면 레지스트리에서 받고(CI가 빌드한 이미지), 없으면 이 서버에서 직접 빌드한다.
# 메모리가 작은 서버(1GB 안팎)에서는 빌드가 실패할 수 있으니 CI에서 빌드한 이미지를 쓰는 편이 안전하다.
set -euo pipefail

COMPOSE=${COMPOSE:-"docker compose --env-file .env.prod -f docker-compose.prod.yml"}
SMOKE_URL=${SMOKE_URL:-}

[ -f .env.prod ] || { echo ".env.prod가 없어요. .env.prod.example을 복사해서 채우세요." >&2; exit 1; }

echo "1/5 백업 (마이그레이션이 데이터를 바꾸기 전에)"
if $COMPOSE ps --status running --services 2>/dev/null | grep -qx db; then
    scripts/backup.sh
else
    echo "  - DB가 아직 없어서(첫 배포) 건너뜀"
fi

echo "2/5 새 버전 받기"
if [ -n "${APP_IMAGE:-$(grep -E '^APP_IMAGE=' .env.prod | cut -d= -f2-)}" ]; then
    $COMPOSE pull app
else
    $COMPOSE build app
fi

echo "3/5 기동"
$COMPOSE up -d --remove-orphans

echo "  앱이 준비될 때까지 기다리는 중..."
for i in $(seq 1 30); do
    status=$(docker inspect --format '{{.State.Health.Status}}' "$($COMPOSE ps -q app)" 2>/dev/null || echo starting)
    [ "$status" = "healthy" ] && break
    [ "$i" -eq 30 ] && { echo "앱이 준비되지 않았어요(상태: $status). 로그: $COMPOSE logs app" >&2; exit 1; }
    sleep 2
done

echo "4/5 마이그레이션과 시딩 (시더는 여러 번 실행해도 안전하다)"
$COMPOSE exec -T app php artisan migrate --force
$COMPOSE exec -T app php artisan db:seed --force

echo "5/5 배포 후 점검"
if [ -n "$SMOKE_URL" ]; then
    scripts/smoke.sh "$SMOKE_URL" "${SMOKE_ORIGIN:-}"
else
    echo "  - SMOKE_URL이 없어 건너뜀. 직접 실행하세요: scripts/smoke.sh https://<API 도메인> https://<프런트 도메인>"
fi

echo "배포 완료"
