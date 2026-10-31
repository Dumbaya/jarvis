<?php

namespace App\Http\Controllers;

use App\Models\Service;
use Illuminate\Http\JsonResponse;

class ServiceRegistryController extends Controller
{
    public function index(): JsonResponse
    {
        $services = Service::query()
            ->where('is_active', true)
            ->with(['tools' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
            ->orderBy('name')
            ->get();

        return response()->json($services);
    }
}
