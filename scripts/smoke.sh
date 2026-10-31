#!/usr/bin/env bash
# 배포 직후 점검. 서버가 떴는지뿐 아니라 "운영에서 반드시 지켜야 할 것"이 지켜지는지 본다.
#
#   scripts/smoke.sh https://api.example.com https://your-app.vercel.app
#   SMOKE_EMAIL=me@example.com SMOKE_PASSWORD=... scripts/smoke.sh https://api.example.com   # 로그인과 인증 API까지 확인
#   CURL_OPTS=-k scripts/smoke.sh https://localhost:8443                                      # 자체 서명 인증서(로컬 검증)
#
# 하나라도 실패하면 종료 코드 1을 돌려준다(CI나 deploy.sh가 배포 실패로 처리할 수 있게).
set -uo pipefail

BASE=${1:?"사용법: scripts/smoke.sh <API 주소> [프런트 출처(CORS 확인용)]"}
ORIGIN=${2:-}
CURL="curl -s -m 15 ${CURL_OPTS:-}"
BASE=${BASE%/}
FAIL=0
PASS=0

ok()   { echo "  ✓ $1"; PASS=$((PASS + 1)); }
fail() { echo "  ✗ $1" >&2; FAIL=$((FAIL + 1)); }
check() { # check "설명" "기대 값" "실제 값"
    if [ "$2" = "$3" ]; then ok "$1"; else fail "$1 (기대: $2, 실제: $3)"; fi
}

echo "점검 대상: $BASE"

echo "[기본 동작]"
check "상태 확인 /up" 200 "$($CURL -o /dev/null -w '%{http_code}' "$BASE/up")"
check "API 전용이라 / 는 404" 404 "$($CURL -o /dev/null -w '%{http_code}' "$BASE/")"
# Accept 헤더가 없어도 500이 아니라 401이어야 한다(과거에 Route [login] not defined 500이 났던 부분).
check "인증 없는 API는 Accept 없이도 401" 401 "$($CURL -o /dev/null -w '%{http_code}' "$BASE/api/auth/me")"

echo "[운영 안전장치]"
HEADERS=$($CURL -I "$BASE/up")
has_header() { echo "$HEADERS" | grep -qi "^$1:"; }
case "$BASE" in
    https://*)
        has_header strict-transport-security && ok "HSTS 헤더" || fail "HSTS 헤더가 없어요"
        HTTP_BASE="http://${BASE#https://}"
        # 포트가 붙은 주소(로컬 검증)는 HTTP 포트를 알 수 없어 건너뛴다.
        case "${BASE#https://}" in
            *:*) echo "  - HTTP→HTTPS 리다이렉트는 포트가 지정된 주소라 건너뜀" ;;
            *) check "HTTP는 HTTPS로 리다이렉트" 301 "$($CURL -o /dev/null -w '%{http_code}' "$HTTP_BASE/up")" ;;
        esac
        ;;
    *) fail "HTTPS가 아니에요. 운영에서는 TLS가 필수예요" ;;
esac
has_header x-content-type-options && ok "X-Content-Type-Options" || fail "X-Content-Type-Options가 없어요"
has_header x-frame-options && ok "X-Frame-Options" || fail "X-Frame-Options가 없어요"
echo "$HEADERS" | grep -qiE "^server: nginx/[0-9]" && fail "서버 버전이 노출돼요(server_tokens off)" || ok "서버 버전 비노출"

# 오류 응답이 코드 경로나 스택 트레이스를 노출하지 않는지(APP_DEBUG=false). 검증 오류(422)는 디버그 모드에서도 정보를
# 담지 않으므로, 실제로 예외가 나는 요청(없는 경로)을 보내야 의미가 있다. 디버그 모드면 exception, file, trace 필드가 붙는다.
BODY=$($CURL "$BASE/api/__smoke_not_found__" -H 'Accept: application/json')
echo "$BODY" | grep -qE '"(trace|exception|file|line)"' && fail "오류 응답에 스택 트레이스가 노출돼요(APP_DEBUG=false로 바꾸세요)" || ok "오류 응답에 내부 정보 없음"

echo "[요청 제한과 CORS]"
if [ -n "$ORIGIN" ]; then
    ALLOW=$($CURL -i -X OPTIONS "$BASE/api/auth/login" -H "Origin: $ORIGIN" -H 'Access-Control-Request-Method: POST' | tr -d '\r' | grep -i '^access-control-allow-origin:' | cut -d' ' -f2-)
    check "프런트 출처가 CORS 허용 목록에 있음" "$ORIGIN" "$ALLOW"
else
    echo "  - 프런트 출처를 주지 않아 CORS 확인은 건너뜀"
fi

if [ -n "${SMOKE_EMAIL:-}" ] && [ -n "${SMOKE_PASSWORD:-}" ]; then
    echo "[로그인과 인증 API]"
    TOKEN=$($CURL -X POST "$BASE/api/auth/login" -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "{\"email\":\"$SMOKE_EMAIL\",\"password\":\"$SMOKE_PASSWORD\"}" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p')
    if [ -n "$TOKEN" ]; then
        ok "로그인"
        check "인증된 /api/usage" 200 "$($CURL -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "$BASE/api/usage")"
        check "서비스 레지스트리 /api/services" 200 "$($CURL -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "$BASE/api/services")"
        $CURL -o /dev/null -X POST -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" "$BASE/api/auth/logout"
    else
        fail "로그인에 실패했어요"
    fi
fi

echo
echo "통과 $PASS, 실패 $FAIL"
[ "$FAIL" -eq 0 ]
