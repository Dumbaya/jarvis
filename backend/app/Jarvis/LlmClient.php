<?php

namespace App\Jarvis;

/**
 * LLM 제공자 인터페이스. Gemini, Groq, Ollama 등을 같은 방식으로 교체할 수 있게 한다.
 *
 * 대화는 제공자에 중립적인 형식으로 주고받는다.
 *  ['role' => 'user'|'assistant', 'text' => string]
 *  ['role' => 'assistant', 'text' => ?string, 'tool_calls' => [['name' => string, 'args' => array]], 'raw' => mixed]
 *  ['role' => 'tool', 'name' => string, 'result' => array]
 * 도구 목록은 ['name', 'description', 'parameters'(JSON Schema)] 배열이다.
 */
interface LlmClient
{
    /** @throws LlmUnavailable 한도 초과, 네트워크 장애 등으로 응답을 받지 못했을 때 */
    public function generate(string $system, array $conversation, array $tools): LlmReply;
}
