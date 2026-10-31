# 아키텍처

## 1. 전체 구성

```mermaid
flowchart TB
    subgraph Client["브라우저"]
        SPA["React Router SPA<br/>(Vercel)"]
    end
    subgraph Server["서버 1대 (Docker Compose)"]
        NG["Nginx<br/>TLS · 요청 제한 · 보안 헤더"]
        NGI["Nginx 내부 서버 :8081<br/>/api/* 만, 외부 비공개"]
        subgraph FPM["PHP-FPM"]
            P1["www 풀 :9000 (ondemand 6)"]
            P2["internal 풀 :9001 (ondemand 4)"]
        end
        DB[("PostgreSQL 16<br/>pgvector · pg_trgm · btree_gist")]
    end
    LLM["LLM 제공자<br/>Gemini · Groq · OpenRouter · Ollama"]
    EMB["임베딩 제공자<br/>Gemini · Ollama"]

    SPA -->|HTTPS + Bearer| NG --> P1
    P1 -->|도구 호출: 사용자 토큰으로 HTTP| NGI --> P2
    P1 --> DB
    P2 --> DB
    P1 -->|도구 선언 + 대화| LLM
    P1 -.->|옵트인한 사용자만| EMB
```

- 프런트와 API는 도메인이 다르다(Vercel vs 서버). 브라우저 요청은 CORS 허용 목록(`CORS_ALLOWED_ORIGINS`)으로만 통과한다.
- 자비스가 도구를 부를 때는 **바깥 요청을 처리 중인 워커와 다른 풀**을 쓴다(교착 방지, [ADR-5](DECISIONS.md#adr-5-내부-호출-전용-php-fpm-풀)).

## 2. 채팅 한 번의 흐름

```mermaid
sequenceDiagram
    actor U as 사용자
    participant C as ChatController
    participant L as ChatLimiter
    participant O as Orchestrator
    participant M as LlmChain
    participant T as ToolExecutor
    participant S as 서비스 API
    participant D as tool_calls (감사 로그)

    U->>C: POST /api/jarvis/chat
    C->>L: 분당 10 / 일 100 확인
    C->>O: 메시지 + 등록된 도구 목록
    O->>M: 대화 + 도구 선언
    M-->>O: memo.search(q) 호출 요청
    O->>T: 읽기 도구 → 즉시 실행
    T->>S: 사용자 본인 토큰으로 HTTP
    T->>D: 인자 / 결과 / 소요 시간 기록
    S-->>T: 결과(데이터로만 취급, 10개·6000자로 자름)
    O->>M: tool 역할 데이터로 결과 전달
    M-->>O: 최종 답변
    O-->>U: 답변 + 남은 횟수

    Note over O,D: 쓰기 도구는 D에 pending으로 저장하고 멈춘다
    U->>C: POST /api/jarvis/actions/{id}/confirm
    C->>D: 조건부 UPDATE (pending → running, 한 번만)
    C->>T: 실행
```

## 3. LLM 체인과 한도 대응

```mermaid
flowchart TD
    Q[채팅 요청] --> B{체인의 모든 모델이<br/>차단 중?}
    B -->|예| K["HTTP 호출 없이<br/>키워드 검색으로 대체<br/>status=quota"]
    B -->|아니오| M1[첫 번째 가용 모델]
    M1 -->|성공| OK[답변]
    M1 -->|429 한도| BL["차단기에 retryDelay만큼 등록<br/>(5초~24시간)"] --> M2[다음 모델]
    M1 -->|5xx / 연결 실패 / 400| SK["이번 요청만 건너뜀<br/>차단하지 않음"] --> M2
    M2 -->|체인 끝| F["LlmUnavailable → 키워드 검색 대체<br/>fallback: true"]
```

- 제공자: `gemini`, `groq`, `openrouter`, `ollama`. 뒤의 셋은 OpenAI 호환 클라이언트를 공유하며, 도구 이름의 `.`을 `__`로 바꿔 보내고 되돌린다.
- 키가 없는 제공자는 건너뛴다. 쓸 수 있는 항목이 없으면 규칙 기반 클라이언트가 처리한다("저장: 내용" 또는 검색어).
- 사용량은 채팅 1건당 `llm_usages` 1행이다(모델이 바뀌어도). 비용은 저장하지 않고 조회 시 설정된 단가로 계산한다.

## 4. 메모 검색 (RAG)

```mermaid
flowchart LR
    subgraph Index["색인 (옵트인한 사용자만)"]
        W[메모 저장/수정] -->|응답 후| CH["Chunker<br/>800자, 100자 겹침, 최대 25조각"]
        CH --> E1["임베딩<br/>RETRIEVAL_DOCUMENT<br/>gemini-embedding-2 · 768차원"]
        E1 --> V[("memo_chunks<br/>vector(768)")]
    end
    subgraph Search["검색"]
        Q["질문"] --> KW["키워드: 단어 AND<br/>(ILIKE + pg_trgm)"]
        Q --> E2["임베딩<br/>RETRIEVAL_QUERY"] --> NN["사용자 필터 후<br/>정확한 코사인 거리"]
        V --> NN
        NN --> TH["유사도 ≥ 0.60<br/>그리고 1위 − 0.10 이상"]
        KW --> RRF["RRF 합산 (k=60)"]
        TH --> RRF
        RRF --> R["결과: match, similarity, excerpt<br/>+ semantic: used/reason"]
    end
```

- 의미 검색을 못 쓰면(`disabled | indexing | unavailable | quota`) 키워드만으로 찾고 이유를 알려 준다.
- SQLite(테스트)에서는 벡터를 JSON 텍스트로 저장하고 PHP로 유사도를 계산한다. PostgreSQL 테스트가 SQL 결과와 PHP 결과의 **순서가 같은지** 직접 비교한다.
- 모델을 바꾸면 현재 모델의 벡터만 검색하고 옛 벡터는 재색인 때 지운다. 차원 768은 컬럼에 고정되어 있어 바꾸려면 마이그레이션과 재색인이 필요하다.

## 5. 데이터 모델

```mermaid
erDiagram
    users ||--o{ memos : 소유
    users ||--o{ reservations : 예약
    users ||--o{ todos : 소유
    users ||--o{ habits : 소유
    users ||--o{ ledger_entries : 소유
    users ||--o{ ledger_rules : 소유
    users ||--o{ tool_calls : 호출
    users ||--o{ llm_usages : 사용
    memos }o--o{ tags : memo_tag
    memos ||--o{ memo_chunks : 조각
    rooms ||--o{ reservations : 대상
    habits ||--o{ habit_checks : 체크
    ledger_rules ||--o{ ledger_entries : 생성
    services ||--o{ service_tools : 등록
    service_tools ||--o{ tool_calls : 호출됨
```

핵심 제약:

| 테이블 | 제약 | 목적 |
|---|---|---|
| `reservations` | `EXCLUDE USING gist` (방 + 시간 범위, 확정만) | 이중 예약 차단 |
| `ledger_entries` | CHECK(amount>0), `unique(rule_id, occurred_on)` | 금액 검증, 고정 거래 중복 방지 |
| `habit_checks` | `unique(habit_id, date)` | 하루 1회 |
| `memo_chunks` | `vector(768)` | 임베딩 저장 |
| `tool_calls` | 상태 컬럼 + 조건부 UPDATE | 쓰기 도구를 한 번만 실행 |

## 6. 코드 구조

```
backend/app/
  Jarvis/     오케스트레이터, LLM 체인/차단기/클라이언트, ToolExecutor, 사용량, 브리핑
  Rag/        임베더, Chunker, VectorStore, MemoIndexer, MemoSearch
  Schedule/   ReservationBooker (예약 트랜잭션)
  Tasks/      HabitStats (연속 달성일 계산, 순수 함수)
  Ledger/     RecurrenceSchedule, RecurringApplier
  Http/Controllers/  서비스별 컨트롤러
  Console/Commands/  memos:eval, memos:index, user:admin
backend/database/seeders/  서비스별 도구 등록 시더 (멱등)
frontend/app/routes/       화면 (대시보드, 메모, 자비스, 일정, 할 일, 가계부, 로그)
docker/    개발/운영 Dockerfile, nginx, php-fpm 풀 설정
scripts/   backup, restore, smoke, deploy
```

새 서비스를 붙이는 방법: API를 만들고, 시더에서 `services`와 `service_tools`에 도구를 등록한다. 허브 코드는 건드리지 않는다.
