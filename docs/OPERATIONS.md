# 운영 가이드

> **상태**: 이 절차는 로컬에서 운영 구성(`docker-compose.prod.yml`)을 실제로 띄워 확인한 것을 바탕으로 작성했습니다. **실제 서버/도메인/Vercel에 배포한 적은 없습니다.** 인증서 발급, GHCR pull, 배포 워크플로는 아직 미검증이니 첫 배포 때 이 문서를 고쳐 가며 진행하세요.

## 구성

| 구성요소 | 위치 | 비고 |
|---|---|---|
| 프런트 | Vercel Hobby | `frontend/vercel.json`(SPA 라우팅) |
| API | 서버 1대, Docker Compose | nginx(80/443) + app(PHP-FPM) + db |
| DB | 같은 서버의 PostgreSQL(pgvector) | 볼륨 보존, 백업 스크립트 |

Vercel은 PHP를 지원하지 않으므로 API는 따로 둡니다. 서버는 AWS(무료 크레딧 기간)나 저비용 VPS 1대면 됩니다. AWS 무료 크레딧의 금액과 기간은 계정마다 달라 확인하지 못했습니다.

## 첫 배포

1. **서버 준비**: Docker와 Compose 설치, 방화벽에서 80/443만 연다(**8081, 5432는 열지 않는다**).
2. **도메인**: API 도메인의 A 레코드를 서버 IP로.
3. **설정 파일**:
   ```bash
   cp .env.prod.example .env.prod
   # SERVER_NAME, POSTGRES_PASSWORD(openssl rand -base64 32), APP_KEY,
   # APP_URL, CORS_ALLOWED_ORIGINS(Vercel 주소), GEMINI_API_KEY 입력
   ```
   `.env.prod`는 저장소에 올리지 않는다(`.gitignore`). `APP_DEBUG=false` 확인.
4. **인증서(최초 1회)**: nginx를 켜기 전에 certbot standalone으로 발급한다.
   ```bash
   sudo certbot certonly --standalone -d api.example.com
   ```
   갱신은 nginx가 켜진 뒤 webroot 방식으로 한다(`/.well-known/acme-challenge/`를 nginx가 서빙).
5. **기동**:
   ```bash
   docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build
   docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan migrate --force
   docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan db:seed --force   # 서비스/도구/방 등록(멱등)
   ```
6. **확인**: `scripts/smoke.sh https://api.example.com https://your-app.vercel.app`
7. **프런트**: Vercel 프로젝트의 Root Directory를 `frontend`로, 환경변수 `VITE_API_URL=https://api.example.com`을 설정한다. **이 값이 없으면 프런트가 API를 찾지 못한다.** 백엔드 `CORS_ALLOWED_ORIGINS`에 Vercel 도메인이 없으면 브라우저가 막는다.
8. **관리자 지정**: `... exec app php artisan user:admin 내이메일`

pgvector 확장은 마이그레이션이 `CREATE EXTENSION`으로 만든다. 관리형 DB(RDS 등)를 쓰면 확장 생성 권한과 지원 여부를 먼저 확인한다(미검증).

## 이후 배포 (CD)

`.github/workflows/deploy.yml`은 **수동 실행 또는 태그 푸시에서만** 동작한다(main 푸시에 자동 배포하지 않음). 이미지를 GHCR에 올리고 SSH로 서버의 `scripts/deploy.sh`를 실행한다.

필요한 GitHub Secrets:

| 이름 | 내용 |
|---|---|
| `DEPLOY_SSH_KEY` | 배포 전용 SSH 개인키 |
| `DEPLOY_KNOWN_HOSTS` | 서버 호스트 키(`ssh-keyscan` 결과, 직접 확인한 것) |
| `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH` | 접속 정보와 서버의 저장소 경로 |
| `DEPLOY_SMOKE_URL`, `DEPLOY_SMOKE_ORIGIN` | 배포 후 확인할 API 주소와 프런트 출처 |

서버에서 GHCR 이미지를 받으려면 서버에서 한 번 `docker login ghcr.io`(읽기 전용 토큰)가 필요하다. `.env.prod`의 `APP_IMAGE`에 이미지를 지정한다.

`deploy.sh`: 배포 전 백업 → 이미지 받기(또는 빌드) → 컨테이너 교체 → 마이그레이션 → 시딩 → 스모크 테스트. 스모크가 실패하면 실패로 끝난다.

## 백업과 복원

```bash
scripts/backup.sh                 # backups/ 에 덤프 생성, 오래된 것 정리
scripts/restore.sh backups/jarvis-<시각>.dump
```

- 크론 예시(매일 03:30): `30 3 * * * cd /srv/jarvis && scripts/backup.sh >> backups/backup.log 2>&1`
- 최근 14개만 보관한다(`BACKUP_KEEP`로 변경). 백업은 **같은 서버에만 두지 말고** 다른 곳으로 복사한다(예: 객체 스토리지). 이 스크립트는 복사까지는 하지 않는다.
- 로컬에서 백업 → 복원 후 테이블별 행 수가 같고 `vector` 타입이 보존됨을 확인했다.
- `restore.sh`는 기본으로 별도 DB `jarvis_restore`에 복원하고 행 수를 보여 준다(운영 DB를 덮어쓰지 않는다). 운영 DB 교체는 확인 후 직접 한다.

## 롤백

1. `APP_IMAGE`를 이전 태그로 되돌리고 `up -d`.
2. 마이그레이션이 포함된 배포였다면 `php artisan migrate:status`를 확인한다. 되돌릴 수 없는 마이그레이션이면 배포 전 백업을 복원한다.

## 보안 점검표

- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `POSTGRES_PASSWORD`가 길고 무작위, 5432가 외부에 열려 있지 않다
- [ ] **8081(내부 풀용 nginx)이 외부에 열려 있지 않다**(compose에서 포트 매핑 없음, 방화벽도 닫기)
- [ ] 80 → 443 리다이렉트, HSTS, 보안 헤더(로컬에서 확인)
- [ ] `/api/auth/*` 요청 제한: nginx(`limit_req`)와 Laravel(분당 10회) 이중
- [ ] `CORS_ALLOWED_ORIGINS`에 실제 프런트 도메인만
- [ ] API 키는 서버 `.env.prod`에만. 채팅/커밋/로그에 붙이지 않는다. 노출됐다면 즉시 폐기하고 재발급
- [ ] 첫 가입 사용자가 관리자가 아니다(관리자는 `user:admin` 명령으로만 지정)

## 자원과 한도

- PHP-FPM: `www` 풀 ondemand 최대 6, `internal` 풀 ondemand 최대 4. 워커당 메모리는 수십 MB 수준이라 **작은 서버(1GB)** 에서도 동작하도록 잡았지만 실서버 메모리는 측정하지 못했다. 서버가 작으면 `docker/php/pools-prod.conf`의 `pm.max_children`을 줄인다. 안쪽 풀을 지나치게 줄이면 ADR-5의 교착이 다시 날 수 있다.
- **Gemini 무료 티어**: 프로젝트·모델당 하루 20회, 모든 사용자 공유. 소진되면 키워드 검색으로 대체되고 화면에 "AI 무료 사용량 소진, N시간 뒤 복구"가 뜬다. 모델이 늘어날수록 총 횟수가 늘어난다(`JARVIS_LLM_CHAIN`).
- 한도 숫자는 바뀐다. 429 오류 본문의 `error.details`가 정확한 값을 알려 준다(로그에 `Gemini 일시 오류 429: …`로 남음).
- 비용: 단가(`GEMINI_PRICE_*` 또는 `JARVIS_PRICING_JSON`)를 넣어야 예상 비용이 계산된다. 무료 티어의 실제 청구는 0원이고 화면의 비용은 "유료였다면" 예상치다.

## 문제 해결

| 증상 | 원인 / 확인 | 조치 |
|---|---|---|
| `docker compose up`이 5432에서 실패 | 로컬의 다른 PostgreSQL이 점유 | `docker-compose.yml`의 호스트 포트만 변경 |
| `storage` 권한 오류(tempnam 500, Windows) | 바인드 마운트 | 컨테이너 시작 시 `entrypoint.sh`가 정리. 계속되면 컨테이너 재시작 |
| 컨테이너에서 `entrypoint.sh` 실행 실패 | CRLF 줄바꿈 | `.gitattributes`가 `*.sh`, docker 설정을 LF로 고정. 이미 CRLF로 받았다면 다시 체크아웃 |
| 자비스가 키워드 검색 결과만 준다 | 한도 소진(`status=quota`) 또는 키 없음 | 대시보드의 AI 상태, 로그의 429 확인. 키/체인 설정 |
| Gemini 404 | 모델 단종 | `GET https://generativelanguage.googleapis.com/v1beta/models`(헤더 `x-goog-api-key`)로 가능한 모델 조회 후 체인 수정 |
| 브라우저에서 CORS 오류 | 허용 출처 누락 | `CORS_ALLOWED_ORIGINS` 수정 후 `php artisan config:cache` 또는 컨테이너 재시작 |
| 프런트가 API를 못 찾음 | `VITE_API_URL` 미설정 | Vercel 환경변수 설정 후 재배포 |
| 동시 요청에서 도구 호출이 멈춤 | 내부 풀로 가는지 확인 | `JARVIS_INTERNAL_URL=http://nginx:8081`인지 확인 |
| 헬스체크 unhealthy | 9000/9001 응답 없음 | `docker compose logs app`, 풀 설정 확인 |
| 설정을 바꿨는데 반영 안 됨 | 운영은 설정/라우트/이벤트 캐시 + opcache `validate_timestamps=0` | 컨테이너 재시작(entrypoint가 캐시를 다시 만든다) |
| 의미 검색이 "색인 중"에서 안 끝남 | 한도 소진 또는 임베딩 키 없음 | `php artisan memos:index`로 이어서 색인 |
| 프런트 빌드가 `ECONNREFUSED` | Docker에서 `localhost`가 IPv6로 풀림 | `vite.config.ts`의 ipv4first(이미 적용) |
| 가계부 고정 거래가 안 생김 | 가계부를 열지 않음 | 열면 밀린 날짜를 한꺼번에 반영한다(ADR-12) |
