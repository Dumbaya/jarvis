<?php

namespace App\Http\Controllers;

use App\Jarvis\Orchestrator;
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
    ) {}

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

        return response()->json($this->orchestrator->chat($request->user(), $request->bearerToken(), $messages));
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
