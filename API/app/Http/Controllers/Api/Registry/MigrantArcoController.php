<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateArcoRequest;
use App\Http\Requests\ResolveArcoRequest;
use App\Models\MigrantArcoRequest;
use App\Services\Registry\MigrantArcoService;
use Illuminate\Http\JsonResponse;

class MigrantArcoController extends Controller
{
    public function __construct(
        private readonly MigrantArcoService $service,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => MigrantArcoRequest::latest()->get(),
        ]);
    }

    public function store(CreateArcoRequest $request): JsonResponse
    {
        $arcoRequest = $this->service->create(
            $request->user(),
            $request->validated(),
        );

        return response()->json([
            'data' => $arcoRequest,
        ], 201);
    }

    public function resolve(
        ResolveArcoRequest $request,
        MigrantArcoRequest $migrantArcoRequest,
    ): JsonResponse {
        $resolved = $this->service->resolve(
            $request->user(),
            $migrantArcoRequest,
            $request->validated(),
        );

        return response()->json([
            'data' => $resolved,
        ]);
    }
}