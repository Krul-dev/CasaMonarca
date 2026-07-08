<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMigrantRegistryRequest;
use App\Http\Requests\SubmitMigrantRegistryRequest;
use App\Http\Requests\UpdateMigrantRegistryRequest;
use App\Models\MigrantRegistryEntry;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantRegistryController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::latest()->get(),
        ]);
    }

    public function store(StoreMigrantRegistryRequest $request): JsonResponse
    {
        $entry = $this->service->create(
            $request->user(),
            $request->validated()['payload_json'],
        );

        return response()->json([
            'data' => $entry,
        ], 201);
    }

    public function show(MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        return response()->json([
            'data' => $migrantRegistryEntry->load([
                'signatures',
                'statusHistory',
            ]),
        ]);
    }

    public function update(
        UpdateMigrantRegistryRequest $request,
        MigrantRegistryEntry $migrantRegistryEntry,
    ): JsonResponse {
        $migrantRegistryEntry->update([
            'payload_json' => $request->validated()['payload_json'],
        ]);

        return response()->json([
            'data' => $migrantRegistryEntry->fresh(),
        ]);
    }

    public function submit(
        SubmitMigrantRegistryRequest $request,
        MigrantRegistryEntry $migrantRegistryEntry,
    ): JsonResponse {
        $entry = $this->service->submit(
            $request->user(),
            $migrantRegistryEntry,
            $request->validated(),
        );

        return response()->json([
            'data' => $entry,
        ]);
    }
}