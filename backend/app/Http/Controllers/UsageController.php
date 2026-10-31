<?php

namespace App\Http\Controllers;

use App\Jarvis\LlmChain;
use App\Jarvis\LlmClient;
use App\Jarvis\UsageReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function __construct(private readonly UsageReport $report) {}

    /**
     * 사용자는 자기 사용량만 받는다. 비용, 모델별 사용량, 예산, AI 연결 상태 같은 운영 정보는
     * 관리자에게만 응답에 포함하고, 일반 사용자 응답에는 항목 자체가 없다.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'me' => $this->report->forUser($user),
            'admin' => $user->is_admin ? $this->report->overall() + ['llm' => $this->llmStatus()] : null,
        ]);
    }

    /** 체인의 모델별 차단 상태. 한도가 소진된 모델과 풀리기까지 남은 시간을 관리자가 볼 수 있다. */
    private function llmStatus(): ?array
    {
        $llm = app(LlmClient::class);

        return $llm instanceof LlmChain ? $llm->status() : null;
    }
}
