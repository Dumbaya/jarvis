<?php

namespace App\Http\Controllers;

use App\Models\Memo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MemoController extends Controller
{
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

        return response()->json($memo->load('tags'));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->find($request, $id)->delete();

        return response()->json(null, 204);
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
