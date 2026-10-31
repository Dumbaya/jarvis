#!/usr/bin/env bash
# 백업을 "새 데이터베이스"로 복원해서 확인한다. 운영 DB를 덮어쓰지 않는 것이 기본이다.
#
#   scripts/restore.sh backups/jarvis-20261031-030000.dump            # jarvis_restore DB로 복원하고 행 수를 보여 준다
#   scripts/restore.sh backups/jarvis-....dump my_check_db             # 복원할 DB 이름 지정
#
# 운영 DB를 정말 교체해야 할 때(재해 복구)는 앱을 멈추고 RESTORE_OVERWRITE=1을 붙인다. docs/OPERATIONS.md의 절차를 먼저 읽는다.
set -euo pipefail

DUMP=${1:?"사용법: scripts/restore.sh <덤프 파일> [복원할 DB 이름]"}
TARGET=${2:-jarvis_restore}
COMPOSE=${COMPOSE:-"docker compose --env-file .env.prod -f docker-compose.prod.yml"}

[ -s "$DUMP" ] || { echo "덤프 파일이 없거나 비어 있어요: $DUMP" >&2; exit 1; }

LIVE=$($COMPOSE exec -T db sh -c 'printf %s "$POSTGRES_DB"')
if [ "$TARGET" = "$LIVE" ] && [ "${RESTORE_OVERWRITE:-0}" != "1" ]; then
    echo "'$TARGET'은 운영 중인 DB예요. 덮어쓰지 않습니다. 정말 교체하려면 앱을 멈추고 RESTORE_OVERWRITE=1을 지정하세요." >&2
    exit 1
fi

echo "복원 대상 DB: $TARGET"
$COMPOSE exec -T db sh -c "psql -U \"\$POSTGRES_USER\" -d postgres -v ON_ERROR_STOP=1 -c 'DROP DATABASE IF EXISTS \"$TARGET\"' -c 'CREATE DATABASE \"$TARGET\"'"
# --no-owner: 덤프를 만든 사용자와 복원하는 사용자가 달라도 소유권 때문에 실패하지 않게 한다.
$COMPOSE exec -T db sh -c "pg_restore -U \"\$POSTGRES_USER\" -d \"$TARGET\" --no-owner --exit-on-error" < "$DUMP"

echo "복원 확인(주요 테이블 행 수):"
$COMPOSE exec -T db sh -c "psql -U \"\$POSTGRES_USER\" -d \"$TARGET\" -At -c \"
SELECT 'users ' || count(*) FROM users UNION ALL
SELECT 'memos ' || count(*) FROM memos UNION ALL
SELECT 'memo_chunks ' || count(*) FROM memo_chunks UNION ALL
SELECT 'reservations ' || count(*) FROM reservations UNION ALL
SELECT 'ledger_entries ' || count(*) FROM ledger_entries UNION ALL
SELECT 'tool_calls ' || count(*) FROM tool_calls;\""
echo "확인이 끝나면 임시 DB는 지운다: $COMPOSE exec -T db sh -c 'psql -U \"\$POSTGRES_USER\" -d postgres -c \"DROP DATABASE $TARGET\"'"
