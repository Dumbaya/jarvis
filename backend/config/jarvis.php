<?php

return [
    // gemini | rule. 지정하지 않으면 GEMINI_API_KEY가 있을 때 gemini, 없으면 키 없이 동작하는 rule을 쓴다.
    'provider' => env('JARVIS_PROVIDER', env('GEMINI_API_KEY') ? 'gemini' : 'rule'),

    // 순서대로 시도할 "제공자/모델" 목록(쉼표 구분). 앞 모델이 한도를 넘기면 다음 모델로 넘어간다.
    // 예: gemini/gemini-3.5-flash,gemini/gemini-3.5-flash-lite,groq/<모델명>
    // 비우면 GEMINI_MODEL 하나만 쓴다. 키가 없는 제공자는 건너뛴다.
    'chain' => env('JARVIS_LLM_CHAIN'),

    // 제공자별 접속 정보. 모델명은 체인에서 지정한다. 무료 모델과 한도는 자주 바뀌니 각 사이트에서 확인한다.
    'providers' => [
        'gemini' => ['api_key' => env('GEMINI_API_KEY'), 'timeout' => 30],
        'groq' => ['base_url' => 'https://api.groq.com/openai/v1', 'api_key' => env('GROQ_API_KEY'), 'timeout' => 30],
        'openrouter' => ['base_url' => 'https://openrouter.ai/api/v1', 'api_key' => env('OPENROUTER_API_KEY'), 'timeout' => 60],
        // 로컬 Ollama. Docker 안에서 호스트의 Ollama에 닿으려면 host.docker.internal을 쓴다.
        'ollama' => ['base_url' => env('OLLAMA_BASE_URL', 'http://host.docker.internal:11434/v1'), 'api_key' => null, 'timeout' => 120],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // 무료 티어에서 쓸 수 있는 모델은 자주 바뀌므로 .env에서 바꿀 수 있게 한다.
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'timeout' => 30,
    ],

    // 사용자에게 보여 주고 "내일 3시" 같은 말을 해석하는 기준 시간대. DB에는 항상 UTC로 저장한다.
    'timezone' => env('JARVIS_TIMEZONE', 'Asia/Seoul'),

    // base_url이 없는 서비스(같은 앱 안의 모듈)를 호출할 때 쓰는 주소. Docker에서는 nginx 컨테이너다.
    'internal_url' => env('JARVIS_INTERNAL_URL', 'http://nginx:8081'),

    // LLM이 한도 초과나 장애로 응답하지 못할 때 사용자의 문장으로 대신 호출할 읽기 도구와 인자 이름
    'fallback_tool' => ['name' => 'memo.search', 'arg' => 'q'],

    // 한 요청 안에서 LLM과 도구를 오가는 최대 횟수 (무료 한도 보호)
    'max_steps' => 4,

    // 쓰기 도구 확인 대기 시간(분)
    'confirm_ttl_minutes' => 10,

    // 모델별 단가(USD / 100만 토큰). 공식 가격표를 보고 .env에 직접 입력한다. 비워 두면 그 모델의 비용은 계산하지 않는다.
    // 무료 티어에서는 실제 청구가 0원이므로, 이 값은 "유료였다면 얼마였을지"를 가늠하는 예상치다.
    // 체인에 모델이 여러 개면 JARVIS_PRICING_JSON으로 한꺼번에 넣는다. 예: {"gemini-3.5-flash":{"input":0.3,"output":2.5}}
    'pricing' => (json_decode((string) env('JARVIS_PRICING_JSON'), true) ?: []) + [
        env('GEMINI_MODEL', 'gemini-3.5-flash') => [
            'input' => env('GEMINI_PRICE_INPUT_PER_M'),
            'output' => env('GEMINI_PRICE_OUTPUT_PER_M'),
        ],
    ],

    // 월 예산(USD, 전체 사용자 합산 예상 비용 기준). 넘으면 AI 호출을 멈추고 키워드 검색으로 대체한다. 비우면 한도 없음.
    // 모든 사용 모델의 단가가 입력되어 있어야 적용된다.
    'monthly_budget_usd' => env('JARVIS_MONTHLY_BUDGET_USD'),

    'rate_limit' => [
        'per_minute' => 10,
        'per_day' => 100,
    ],
];
