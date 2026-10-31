<?php

namespace App\Http\Controllers;

use App\Jarvis\ChatLimiter;
use App\Jarvis\LlmChain;
use App\Jarvis\LlmClient;
use App\Jarvis\Orchestrator;
use App\Jarvis\RuleBasedClient;
use App\Jarvis\ToolExecutor;
use App\Models\ToolCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class JarvisController extends Controller
{
    public function __construct(
        private readonly Orchestrator $orchestrator,
        private readonly ToolExecutor $executor,
        private readonly ChatLimiter $limiter,
    ) {}

    /** 남은 채팅 횟수(분당, 일일)와 AI 사용 가능 여부. 조회만 하고 횟수를 소비하지 않는다. */
    public function limits(Request $request): JsonResponse
    {
        return response()->json($this->limitsFor($request->user()));
    }

    /**
     * 채팅 횟수 제한(우리 앱의 한도)과 AI 제공자 쪽 한도 상태를 함께 내려 준다.
     * 둘은 다르다. 내 횟수가 남아 있어도 무료 AI 한도가 소진되면 키워드 검색으로 동작한다.
     * 모델 이름 같은 운영 정보는 내려 주지 않는다(관리자 대시보드에서만 보인다).
     */
    private function limitsFor($user): array
    {
        $llm = app(LlmClient::class);
        $status = $llm instanceof LlmChain ? $llm->status() : null;

        return $this->limiter->status($user) + ['ai' => [
            'mode' => $llm instanceof RuleBasedClient ? 'rule' : 'llm',
            'available' => $status['available'] ?? true,
            'retry_after' => $status['retry_after'] ?? null,
        ]];
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'messages' => ['required', 'array', 'min:1', 'max:20'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:4000'],
        ]);

        $messages = array_values($data['messages']);
        if (end($messages)['role'] !== 'user') {
            throw ValidationException::withMessages(['messages' => ['마지막 메시지는 사용자 메시지여야 합니다.']]);
        }

        $user = $request->user();

        if (! $this->limiter->attempt($user)) {
            $wait = $this->limiter->retryAfter($user);

            return response()->json([
                'message' => '요청 한도를 넘었어요. '.$this->humanize($wait).' 뒤에 다시 시도해 주세요.',
                'retry_after' => $wait,
                'limits' => $this->limitsFor($user),
            ], 429)->header('Retry-After', (string) $wait);
        }

        $result = $this->orchestrator->chat($user, $request->bearerToken(), $messages);
        $result['limits'] = $this->limitsFor($user);

        return response()->json($result);
    }

    private function humanize(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => max(1, $seconds).'초',
            $seconds < 7200 => (int) ceil($seconds / 60).'분',
            default => (int) ceil($seconds / 3600).'시간',
        };
    }

    /** 내 호출 기록(감사 로그). 결과 본문은 크고 민감할 수 있어 목록에는 넣지 않는다. */
    public function calls(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'in:pending,running,success,error,rejected,cancelled'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            // 기본은 자비스 대화에서 일어난 호출만. 대시보드 브리핑이 남긴 조회 기록은 source=all로 함께 본다.
            'source' => ['nullable', 'in:jarvis,briefing,all'],
        ]);
        $source = $request->query('source', 'jarvis');

        // 요청이 도중에 끊기면(타임아웃, 서버 재시작) 기록이 running으로 영영 남는다. 오래된 것은 중단된 것으로 정리한다.
        $request->user()->toolCalls()
            ->where('status', 'running')->where('created_at', '<', now()->subMinutes(5))
            ->update(['status' => 'error', 'error' => '응답을 받지 못하고 중단됐어요.']);

        $calls = $request->user()->toolCalls()
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->when($source !== 'all', fn ($q) => $q->where('source', $source))
            ->latest('id')
            ->paginate($request->integer('per_page', 20), ['id', 'tool_name', 'arguments', 'status', 'source', 'error', 'duration_ms', 'created_at']);

        return response()->json($calls);
    }

    /** 사용자가 확인한 쓰기 작업을 실행한다. */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $call = $this->claimPending($request, $id);

        $tool = $this->orchestrator->findToolFor($call);
        if (! $tool) {
            $call->update(['status' => 'error', 'error' => '더 이상 사용할 수 없는 도구입니다.']);

            return response()->json(['status' => 'error', 'message' => '더 이상 사용할 수 없는 도구입니다.'], 422);
        }

        $call = $this->executor->run($call, $tool, $request->bearerToken());

        return response()->json([
            'status' => $call->status,
            'message' => $call->status === 'success' ? '실행했어요.' : ($call->error ?? '실행하지 못했어요.'),
            'result' => $call->result['data'] ?? null,
        ], $call->status === 'success' ? 200 : 502);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $call = $this->claimPending($request, $id, 'cancelled');

        return response()->json(['status' => $call->status, 'message' => '취소했어요.']);
    }

    /**
     * 내 확인 대기 작업을 한 번만 선점한다. 더블 클릭이나 재요청으로 같은 작업이 두 번 실행되지 않게
     * 상태를 조건부 UPDATE로 바꾸고, 바뀐 행이 없으면 거절한다.
     */
    private function claimPending(Request $request, int $id, string $to = 'running'): ToolCall
    {
        $call = $request->user()->toolCalls()->findOrFail($id);

        $claimed = ToolCall::query()
            ->whereKey($call->id)
            ->where('status', 'pending')
            ->where('created_at', '>=', now()->subMinutes(config('jarvis.confirm_ttl_minutes')))
            ->update(['status' => $to]);

        abort_if($claimed === 0, 409, '이미 처리되었거나 만료된 요청입니다.');

        return $call->refresh();
    }
}
