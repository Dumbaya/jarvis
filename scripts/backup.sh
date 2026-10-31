#!/usr/bin/env bash
# PostgreSQL 백업(pg_dump 사용자 지정 형식). 사용자 데이터가 들어 있으므로 backups/ 폴더는 권한을 좁히고 저장소에 올리지 않는다.
#
#   scripts/backup.sh
#   BACKUP_KEEP=30 scripts/backup.sh          # 최근 30개만 보관 (기본 14)
#
# cron 예시(매일 새벽 3시): 0 3 * * * cd /srv/jarvis && scripts/backup.sh >> backups/backup.log 2>&1
# 서버 디스크가 망가지면 같이 사라지므로, 백업 폴더는 다른 곳(S3 등)에도 복사해 두는 것이 안전하다.
set -euo pipefail

COMPOSE=${COMPOSE:-"docker compose --env-file .env.prod -f docker-compose.prod.yml"}
OUT=${BACKUP_DIR:-backups}
KEEP=${BACKUP_KEEP:-14}

mkdir -p "$OUT"
chmod 700 "$OUT"
FILE="$OUT/jarvis-$(date +%Y%m%d-%H%M%S).dump"

# 임시 이름으로 받아서 끝까지 성공했을 때만 정식 이름으로 바꾼다(중간에 끊긴 백업이 정상 파일처럼 남지 않게).
$COMPOSE exec -T db sh -c 'pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc' > "$FILE.tmp"

if [ ! -s "$FILE.tmp" ]; then
    rm -f "$FILE.tmp"
    echo "백업 파일이 비어 있어요. 중단합니다." >&2
    exit 1
fi

# 덤프를 실제로 읽을 수 있는지(목차 조회) 확인한다. 읽을 수 없는 백업은 백업이 아니다.
if ! $COMPOSE exec -T db pg_restore --list < "$FILE.tmp" > /dev/null; then
    rm -f "$FILE.tmp"
    echo "백업 파일을 읽을 수 없어요. 중단합니다." >&2
    exit 1
fi

mv "$FILE.tmp" "$FILE"
chmod 600 "$FILE"

# 오래된 백업을 지운다(최근 KEEP개만 남긴다).
ls -1t "$OUT"/jarvis-*.dump 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do rm -f "$old"; done

echo "백업 완료: $FILE ($(du -h "$FILE" | cut -f1)), 보관 ${KEEP}개"
