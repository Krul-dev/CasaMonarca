<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentApprovalRejectController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if ($document->status !== 'pending_approval') {
            return response()->json([
                'message' => 'This document is not pending approval.',
                'error' => [
                    'code' => 'document_not_pending_approval',
                ],
            ], 409);
        }

        $document->load(['currentRevision', 'revisions']);
        $documentId = (int) $document->getKey();
        $revisionId = $document->currentRevision?->getKey();
        $title = $document->title;
        $storagePaths = $document->revisions
            ->map(fn ($revision): array => [
                'disk' => $revision->storage_disk,
                'path' => $revision->storage_path,
            ])
            ->values()
            ->all();

        DB::transaction(function () use ($actor, $document, $documentId, $request, $revisionId, $title, $validated): void {
            $document->forceFill([
                'current_revision_id' => null,
            ])->save();

            $document->delete();

            $this->auditEventService->success(
                $request,
                AuditEventType::DocumentApprovalRejected,
                $actor,
                [
                    'type' => 'document',
                    'id' => $documentId,
                    'documentId' => $documentId,
                    'revisionId' => $revisionId,
                ],
                [
                    'reason' => $validated['reason'] ?? null,
                    'title' => $title,
                ],
            );
        });

        foreach ($storagePaths as $storageReference) {
            $disk = (string) ($storageReference['disk'] ?? 'local');
            $path = (string) ($storageReference['path'] ?? '');

            if ($path === '' || ! Storage::disk($disk)->exists($path)) {
                continue;
            }

            if (! Storage::disk($disk)->delete($path)) {
                report(new \RuntimeException(sprintf(
                    'Rejected document payload cleanup failed for document %d at %s:%s',
                    $documentId,
                    $disk,
                    $path,
                )));
            }
        }

        return response()->json([
            'message' => 'Document rejected and removed successfully.',
            'rejectedDocument' => [
                'id' => $documentId,
                'title' => $title,
                'revisionId' => $revisionId,
            ],
        ]);
    }
}
