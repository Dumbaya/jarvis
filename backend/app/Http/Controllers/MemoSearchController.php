<?php

namespace App\Http\Controllers;

use App\Rag\MemoIndexer;
use App\Rag\MemoSearch;
use App\Rag\VectorStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemoSearchController extends Controller
{
    public function __construct(
        private readonly MemoSearch $search,
        private readonly MemoIndexer $indexer,
        private readonly VectorStore $store,
    ) {}

    /** 키워드와 의미 검색을 합친 메모 검색. 자비스의 memo.search 도구도 이 경로를 쓴다. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['required', 'string', 'max:200'],
            'tag' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
        $user = $request->user();

        $result = $this->search->search($user, trim($data['q']), $data['tag'] ?? null, (int) ($data['limit'] ?? 5));

        // 응답을 보낸 뒤에 아직 색인되지 않은 메모를 조금씩 색인한다(켠 사용자만). 별도 워커 프로세스가 필요 없다.
        if ($user->memo_ai_indexing) {
            $this->indexLater(fn () => $this->indexer->indexPending($user, 20));
        }

        return response()->json($result);
    }

    /** 의미 검색 상태: 켜져 있는지, 색인 진행률, 지금 쓸 수 있는지. */
    public function status(Request $request): JsonResponse
    {
        return response()->json($this->statusFor($request->user()));
    }

    /**
     * 의미 검색 켜기/끄기. 켜면 그때부터 메모 내용이 임베딩 제공자로 전송되어 색인된다.
     * 끄면 저장된 벡터를 모두 지운다(벡터는 원문의 일부 정보를 담고 있다).
     */
    public function toggle(Request $request): JsonResponse
    {
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $user = $request->user();

        $user->forceFill(['memo_ai_indexing' => $enabled])->save();

        if ($enabled) {
            $this->indexLater(fn () => $this->indexer->indexPending($user, 50));
        } else {
            $this->store->purgeUser($user->id);
        }

        return response()->json($this->statusFor($user->refresh()));
    }

    private function statusFor($user): array
    {
        $model = $this->indexer->model();

        return [
            'enabled' => $user->memo_ai_indexing,
            'model' => $model,
            'total' => $user->memos()->count(),
            'indexed' => $this->store->indexedMemoCount($user->id, $model),
            // 한도로 막혀 있는지와 풀리기까지의 시간. 막혀 있어도 키워드 검색은 된다.
            'available' => $this->indexer->available(),
            'retry_after' => $this->indexer->available() ? null : $this->indexer->retryAfter(),
        ];
    }

    /**
     * 응답을 보낸 뒤 한 번만 실행한다. 실패해도 이미 끝난 요청에는 영향이 없으므로 기록만 남긴다.
     * terminating 콜백은 같은 앱 인스턴스가 여러 요청을 처리하면 요청마다 다시 실행되므로, 외부 호출이 반복되지 않게 막는다.
     */
    private function indexLater(callable $work): void
    {
        $done = false;
        app()->terminating(function () use ($work, &$done) {
            if ($done) {
                return;
            }
            $done = true;

            try {
                $work();
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }
}
