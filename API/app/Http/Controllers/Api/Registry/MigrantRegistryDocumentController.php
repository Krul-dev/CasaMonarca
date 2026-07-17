<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Services\Audit\AuditEventService;
use App\Services\Registry\MigrantRegistryDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MigrantRegistryDocumentController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryDocumentService $service,
        private readonly AuditEventService $auditService,
    ) {}

    public function index(MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        return response()->json([
            'data' => $migrantRegistryEntry->documents()
                ->whereNull('purged_at')
                ->with('uploader:id,name,email,role')
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'max:16384'],
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

    public function download(
        Request $request,
        MigrantRegistryEntry $migrantRegistryEntry,
        MigrantRegistryDocument $migrantRegistryDocument,
    ): StreamedResponse {
        $this->authorizeDocument($migrantRegistryEntry, $migrantRegistryDocument);

        if ($migrantRegistryDocument->purged_at !== null || ! $migrantRegistryDocument->storage_path) {
            abort(410, 'This document is no longer available.');
        }

        $this->auditService->success(
            $request,
            AuditEventType::MigrantDocumentDownloaded,
            $request->user(),
            ['type' => MigrantRegistryDocument::class, 'id' => $migrantRegistryDocument->getKey()],
            [
                'registryEntryId' => $migrantRegistryEntry->getKey(),
                'originalFileName' => $migrantRegistryDocument->original_file_name,
                'sha256' => $migrantRegistryDocument->sha256,
            ],
        );

        return Storage::disk($migrantRegistryDocument->storage_disk ?? 'local')->download(
            $migrantRegistryDocument->storage_path,
            $migrantRegistryDocument->original_file_name,
        );
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
