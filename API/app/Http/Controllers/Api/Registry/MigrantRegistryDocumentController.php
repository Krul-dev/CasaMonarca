<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Services\Registry\MigrantArcoService;
use App\Services\Registry\MigrantRegistryDocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantRegistryDocumentController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryDocumentService $service,
    ) {}

    public function index(MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $latestCompletedAccessAt = MigrantArcoRequest::query()
            ->where('registry_entry_id', $migrantRegistryEntry->getKey())
            ->where('request_type', 'access')
            ->where('status', MigrantArcoService::STATUS_COMPLETED)
            ->max('completed_at');
        $documents = $migrantRegistryEntry->documents()
            ->whereNull('purged_at')
            ->with('uploader:id,name,email,role')
            ->latest()
            ->get();

        $documents->each(function (MigrantRegistryDocument $document) use ($latestCompletedAccessAt): void {
            $document->setAttribute(
                'arco_access_completed',
                is_string($latestCompletedAccessAt) && $document->created_at !== null
                    && $document->created_at->lessThanOrEqualTo(CarbonImmutable::parse($latestCompletedAccessAt)),
            );
        });

        return response()->json([
            'data' => $documents,
        ]);
    }

    public function store(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:16384',
                'mimetypes:'.implode(',', config('features.migrant_documents_allowed_mime_types', [])),
            ],
            'label' => ['nullable', 'string', 'max:255'],
        ]);

        $document = $this->service->store(
            $request->user(),
            $migrantRegistryEntry,
            $validated['file'],
            $validated['label'] ?? null,
        );

        return response()->json([
            'data' => $document->load('uploader:id,name,email,role'),
        ], 201);
    }

    public function destroy(
        Request $request,
        MigrantRegistryEntry $migrantRegistryEntry,
        MigrantRegistryDocument $migrantRegistryDocument,
    ): JsonResponse {
        $this->authorizeDocument($migrantRegistryEntry, $migrantRegistryDocument);

        $this->service->delete($request->user(), $migrantRegistryEntry, $migrantRegistryDocument);

        return response()->json([
            'message' => 'Document removed from the registration.',
        ]);
    }

    private function authorizeDocument(
        MigrantRegistryEntry $entry,
        MigrantRegistryDocument $document,
    ): void {
        abort_unless($document->registry_entry_id === $entry->getKey(), 404);
    }
}
