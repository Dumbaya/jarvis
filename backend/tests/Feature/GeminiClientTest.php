<?php

namespace Tests\Feature;

use App\Jarvis\GeminiClient;
use App\Jarvis\LlmUnavailable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiClientTest extends TestCase
{
    private function client(): GeminiClient
    {
        return new GeminiClient('test-key', 'test-model');
    }

    public function test_empty_function_call_args_are_replayed_as_an_object_not_a_list(): void
    {
        // 인자 없는 도구를 호출하면 Gemini는 args를 비워 보낸다. 이를 다음 요청에 되돌릴 때 []가 되면 400이 난다.
        Http::fake(['*' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'schedule.list_rooms', 'args' => []]]]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => '방은 3개예요.']]]]]]),
        ]);

        $first = $this->client()->generate('sys', [['role' => 'user', 'text' => '방 목록']], []);
        $this->assertSame('schedule.list_rooms', $first->toolCalls[0]['name']);

        $this->client()->generate('sys', [
            ['role' => 'user', 'text' => '방 목록'],
            ['role' => 'assistant', 'text' => null, 'tool_calls' => $first->toolCalls, 'raw' => $first->raw],
            ['role' => 'tool', 'name' => 'schedule.list_rooms', 'result' => ['data' => []]],
        ], []);

        // assertSent는 하나라도 조건을 만족하면 통과하므로, 검사할 두 번째 요청을 직접 꺼내 본다.
        $recorded = Http::recorded();
        $this->assertCount(2, $recorded);
        $secondBody = $recorded[1][0]->body();

        $this->assertStringContainsString('functionResponse', $secondBody);
        // 되돌려 보낸 functionCall의 args는 JSON 객체여야 한다.
        $this->assertStringContainsString('"functionCall":{"name":"schedule.list_rooms","args":{}}', $secondBody);
    }

    public function test_token_usage_includes_thinking_tokens_in_output(): void
    {
        // 총합(total)에서 입력을 뺀 값이 출력이다. 후보 토큰만 쓰면 추론(thoughts) 토큰이 과금에서 빠진다.
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'hi']]]]],
            'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 80, 'totalTokenCount' => 200],
        ])]);

        $reply = $this->client()->generate('sys', [['role' => 'user', 'text' => 'hi']], []);

        $this->assertSame(['input' => 100, 'output' => 100], $reply->usage);
        $this->assertSame('test-model', $reply->model);
    }

    public function test_missing_usage_metadata_yields_zero_tokens(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]]])]);

        $this->assertSame(['input' => 0, 'output' => 0], $this->client()->generate('sys', [['role' => 'user', 'text' => 'hi']], [])->usage);
    }

    public function test_key_is_sent_in_header_not_in_url(): void
    {
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'hi']]]]]])]);

        $this->client()->generate('sys', [['role' => 'user', 'text' => 'hi']], []);

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($r->url(), 'test-key'));
    }

    public function test_rate_limit_and_server_errors_become_llm_unavailable(): void
    {
        foreach ([429, 500, 503] as $status) {
            Http::fake(['*' => Http::response(['error' => 'x'], $status)]);

            try {
                $this->client()->generate('sys', [['role' => 'user', 'text' => 'hi']], []);
                $this->fail("{$status}에서 예외가 나야 합니다.");
            } catch (LlmUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
