<?php

return [
    // gemini | rule. 지정하지 않으면 GEMINI_API_KEY가 있을 때 gemini, 없으면 키 없이 동작하는 rule을 쓴다.
    'provider' => env('JARVIS_PROVIDER', env('GEMINI_API_KEY') ? 'gemini' : 'rule'),

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        // 무료 티어에서 쓸 수 있는 모델은 자주 바뀌므로 .env에서 바꿀 수 있게 한다.
        'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
        'timeout' => 30,
    ],

    // base_url이 없는 서비스(같은 앱 안의 모듈)를 호출할 때 쓰는 주소. Docker에서는 nginx 컨테이너다.
    'internal_url' => env('JARVIS_INTERNAL_URL', 'http://nginx'),

    // LLM이 한도 초과나 장애로 응답하지 못할 때 사용자의 문장으로 대신 호출할 읽기 도구와 인자 이름
    'fallback_tool' => ['name' => 'memo.search', 'arg' => 'q'],

    // 한 요청 안에서 LLM과 도구를 오가는 최대 횟수 (무료 한도 보호)
    'max_steps' => 4,

    // 쓰기 도구 확인 대기 시간(분)
    'confirm_ttl_minutes' => 10,

    'rate_limit' => [
        'per_minute' => 10,
        'per_day' => 100,
    ],
];
