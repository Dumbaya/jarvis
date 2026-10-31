<?php

namespace App\Jarvis;

use App\Models\ServiceTool;
use App\Models\ToolCall;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 레지스트리에 등록된 도구를 HTTP로 호출한다.
 * 호출은 항상 사용자 본인의 토큰으로 하므로 자비스가 사용자보다 많은 권한을 갖지 않는다.
 * 같은 앱 안의 모듈도, 나중에 분리된 서비스도 같은 경로를 탄다.
 */
class ToolExecutor
{
    /** 모델에게 돌려줄 결과의 최대 길이. 길면 무료 한도와 맥락을 낭비한다. */
    private const MAX_RESULT_CHARS = 6000;

    private const MAX_LIST_ITEMS = 10;

    /**
     * 스키마에 없는 인자는 버리고, 필수 인자가 빠지면 예외를 던진다.
     *
     * @throws InvalidToolArguments
     */
    public function sanitize(ServiceTool $tool, array $args): array
    {
        $schema = $tool->parameters ?? [];
        $allowed = array_keys($schema['properties'] ?? []);

        $clean = array_intersect_key($args, array_flip($allowed));
        $clean = array_filter($clean, fn ($v) => $v !== null && $v !== '');

        foreach ($schema['required'] ?? [] as $field) {
            if (! array_key_exists($field, $clean)) {
                throw new InvalidToolArguments("필수 인자 '{$field}'가 없습니다.");
            }
        }

        return $clean;
    }

    public function record(User $user, ServiceTool $tool, array $args, string $status, string $source = 'jarvis'): ToolCall
    {
        return ToolCall::create([
            'user_id' => $user->id,
            'service_tool_id' => $tool->id,
            'tool_name' => $tool->name,
            'arguments' => $args,
            'status' => $status,
            'source' => $source,
        ]);
    }

    /** 기록된 호출을 실제로 보내고, 결과와 소요 시간을 같은 기록에 저장한다. */
    public function run(ToolCall $call, ServiceTool $tool, string $token): ToolCall
    {
        $started = hrtime(true);

        try {
            $data = $this->send($tool, $call->arguments, $token);
            $call->fill(['status' => 'success', 'result' => ['data' => $data]]);
        } catch (ToolCallFailed $e) {
            $call->fill(['status' => 'error', 'error' => $e->getMessage()]);
        }

        $call->duration_ms = (int) ((hrtime(true) - $started) / 1_000_000);
        $call->save();

        return $call;
    }

    /** LLM에 넘길 결과. 목록은 앞부분만 남기고 전체 길이를 제한한다. */
    public function forModel(ToolCall $call): array
    {
        if ($call->status !== 'success') {
            return ['error' => $call->error ?? '도구 호출에 실패했습니다.'];
        }

        $data = $call->result['data'] ?? null;

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            $data = ['data' => array_slice($data['data'], 0, self::MAX_LIST_ITEMS), 'total' => $data['total'] ?? null];
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        if (mb_strlen($json) > self::MAX_RESULT_CHARS) {
            return ['truncated' => true, 'text' => mb_substr($json, 0, self::MAX_RESULT_CHARS)];
        }

        return is_array($data) ? $data : ['value' => $data];
    }

    /**
     * 경로의 {이름} 자리를 같은 이름의 인자로 채우고, 쓴 인자는 본문/쿼리에서 뺀다.
     * 예: /api/reservations/{reservation_id}/cancel
     *
     * @return array{0: string, 1: array}
     */
    private function fillPath(string $path, array $args): array
    {
        $path = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$args) {
            if (! array_key_exists($m[1], $args)) {
                throw new ToolCallFailed("경로 인자 '{$m[1]}'가 없습니다.");
            }
            $value = rawurlencode((string) $args[$m[1]]);
            unset($args[$m[1]]);

            return $value;
        }, $path);

        return [$path, $args];
    }

    private function send(ServiceTool $tool, array $args, string $token): mixed
    {
        [$path, $args] = $this->fillPath($tool->path, $args);
        $base = rtrim($tool->service->base_url ?: config('jarvis.internal_url'), '/');
        $url = $base.'/'.ltrim($path, '/');

        $request = Http::withToken($token)->acceptJson()->timeout(15);

        try {
            $response = strtoupper($tool->http_method) === 'GET'
                ? $request->get($url, $args)
                : $request->send($tool->http_method, $url, ['json' => $args]);
        } catch (ConnectionException $e) {
            throw new ToolCallFailed('서비스에 연결하지 못했습니다.');
        }

        if ($response->failed()) {
            $message = $response->json('message') ?? $response->reason();
            throw new ToolCallFailed("서비스 오류 ({$response->status()}): {$message}");
        }

        return $response->json();
    }
}
