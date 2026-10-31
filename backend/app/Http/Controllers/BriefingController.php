<?php

namespace App\Http\Controllers;

use App\Jarvis\Briefing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BriefingController extends Controller
{
    public function __construct(private readonly Briefing $briefing) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', 'in:today,week']]);

        return response()->json($this->briefing->build($request->user(), $request->bearerToken(), $data['period'] ?? 'today'));
    }
}
