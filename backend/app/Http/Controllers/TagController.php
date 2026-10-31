<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TagController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // withCount가 select를 채우면 get()의 컬럼 지정이 무시되므로 select를 먼저 지정한다.
        $tags = $request->user()->tags()
            ->select('tags.id', 'tags.name')
            ->whereHas('memos')
            ->withCount('memos')
            ->orderByDesc('memos_count')
            ->orderBy('name')
            ->get()
            ->map->only(['id', 'name']);

        return response()->json($tags->values());
    }
}
