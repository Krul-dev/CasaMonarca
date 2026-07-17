<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMigrantRegistryRequest;
use App\Http\Requests\SubmitMigrantRegistryRequest;
use App\Http\Requests\UpdateMigrantRegistryRequest;
use App\Models\MigrantRegistryEntry;
use App\Services\Registry\MigrantRegistryDocumentService;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MigrantRegistryController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $service,
        private readonly MigrantRegistryDocumentService $documentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::query()
                ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
                ->latest()
                ->get(),
        ]);
    }

    public function pendingApproval(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::query()
                ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
                ->where('current_status', MigrantRegistryService::STATUS_PENDING_APPROVAL)
                ->latest()
                ->get(),
        ]);
    }

    public function pendingReview(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::query()
                ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
                ->where('current_status', MigrantRegistryService::STATUS_PENDING_REVIEW)
                ->latest()
                ->get(),
        ]);
    }

    public function corrections(Request $request): JsonResponse
    {
        return response()->json([
            'data' => MigrantRegistryEntry::query()
                ->with(['creator:id,name,email,role', 'signatures', 'statusHistory'])
                ->where('current_status', MigrantRegistryService::STATUS_CHANGES_REQUESTED)
                ->where('created_by', $request->user()?->getKey())
                ->latest()
                ->get(),
        ]);
    }

    public function store(StoreMigrantRegistryRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $storedDocuments = collect();

        try {
            $entry = DB::transaction(function () use ($request, $validated, $storedDocuments): MigrantRegistryEntry {
                $entry = $this->service->create(
                    $request->user(),
                    $validated['payload_json'],
                );

                foreach ($validated['documents'] ?? [] as $index => $file) {
                    $storedDocuments->push($this->documentService->store(
                        $request->user(),
                        $entry,
                        $file,
                        $validated['document_labels'][$index] ?? null,
                    ));
                }

                return $entry;
            });
        } catch (\Throwable $exception) {
            $this->documentService->cleanupStoredFiles($storedDocuments);

            throw $exception;
        }

        return response()->json([
            'data' => $entry,
        ], 201);
    }

    public function show(MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        return response()->json([
            'data' => $migrantRegistryEntry->load([
                'creator:id,name,email,role',
                'signatures',
                'statusHistory',
            ]),
        ]);
    }

    public function update(
        UpdateMigrantRegistryRequest $request,
        MigrantRegistryEntry $migrantRegistryEntry,
    ): JsonResponse {
        $validated = $request->validated();
        $storedDocuments = collect();

        try {
            $entry = DB::transaction(function () use ($request, $validated, $migrantRegistryEntry, $storedDocuments): MigrantRegistryEntry {
                $entry = $this->service->requestUpdate(
                    $request->user(),
                    $migrantRegistryEntry,
                    $validated['payload_json'],
                );

                foreach ($validated['documents'] ?? [] as $index => $file) {
                    $storedDocuments->push($this->documentService->store(
                        $request->user(),
                        $entry,
                        $file,
                        $validated['document_labels'][$index] ?? null,
                    ));
                }

                return $entry;
            });
        } catch (\Throwable $exception) {
            $this->documentService->cleanupStoredFiles($storedDocuments);

            throw $exception;
        }

        return response()->json([
            'data' => $entry,
        ]);
    }

    public function destroy(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $this->service->delete($request->user(), $migrantRegistryEntry);

        return response()->json([
            'message' => 'Migrant registration deleted.',
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
