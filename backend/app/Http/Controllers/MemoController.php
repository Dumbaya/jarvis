<?php

namespace App\Http\Controllers;

use App\Models\Memo;
use App\Rag\MemoIndexer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MemoController extends Controller
{
    public function __construct(private readonly MemoIndexer $indexer) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'tag' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $memos = $request->user()->memos()
            ->with('tags')
            ->search($request->query('q'))
            ->tagged($request->query('tag'))
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return response()->json($memos);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $memo = $request->user()->memos()->create($data);
        $this->syncTags($request, $memo, $data['tags'] ?? []);
        $this->indexLater($request, $memo);

        return response()->json($memo->load('tags'), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($this->find($request, $id)->load('tags'));
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $memo = $this->find($request, $id);
        $data = $this->validated($request);

        $memo->update($data);
        if (array_key_exists('tags', $data)) {
            $this->syncTags($request, $memo, $data['tags']);
        }

        // 내용이 바뀌면 옛 내용의 벡터가 검색에 걸리면 안 되므로 즉시 지우고, 켠 사용자는 다시 색인한다.
        // 태그만 바꾼 경우는 임베딩에 영향이 없어서 건드리지 않는다.
        if ($memo->wasChanged(['title', 'body'])) {
            $memo->chunks()->delete();
            $this->indexLater($request, $memo);
        }

        return response()->json($memo->load('tags'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
    }

    /**
     * 의미 검색을 켠 사용자의 메모만, 응답을 보낸 뒤에 색인한다. 끈 사용자의 메모는 임베딩 제공자에게 전송되지 않는다.
     * 색인이 실패해도 저장은 이미 끝났고, 나중에 검색이나 memos:index 때 다시 시도한다.
     */
    private function indexLater(Request $request, Memo $memo): void
    {
        if (! $request->user()->memo_ai_indexing) {
            return;
        }

        // terminating 콜백은 같은 앱 인스턴스가 여러 요청을 처리하면(테스트, Octane 같은 환경) 요청마다 다시 실행된다.
        // 임베딩은 외부 호출이라 두 번 돌면 안 되므로 한 번만 실행되게 막는다.
        $done = false;
        app()->terminating(function () use ($memo, &$done) {
            if ($done) {
                return;
            }
            $done = true;

            try {
                $this->indexer->index($memo->fresh() ?? $memo);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    private function find(Request $request, int $id): Memo
    {
        return $request->user()->memos()->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'tags' => ['sometimes', 'array', 'max:10'],
            // 빈 칸은 null로 들어오므로 허용하고, syncTags에서 걸러낸다.
            'tags.*' => ['nullable', 'string', 'max:50'],
        ]);
    }

    /** 태그는 앞뒤 공백을 지우고 소문자로 통일해서 사용자별로 하나만 유지한다. */
    private function syncTags(Request $request, Memo $memo, array $names): void
    {
        $tagIds = Collection::make($names)
            ->map(fn (?string $n) => mb_strtolower(trim((string) $n)))
            ->filter()
            ->unique()
            ->map(fn (string $n) => $request->user()->tags()->firstOrCreate(['name' => $n])->id);

        $memo->tags()->sync($tagIds->all());
    }
}
