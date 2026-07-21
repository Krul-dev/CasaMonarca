<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentStoreController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:16384'],
        ]);

        /** @var User $user */
        $user = $request->user();
        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $validated['file'];
        $originalFileName = basename($uploadedFile->getClientOriginalName());

        $title = trim((string) ($validated['title'] ?? ''));

        if ($title === '') {
            $title = (string) pathinfo($originalFileName, PATHINFO_FILENAME);
        }

        $storedPath = null;

        try {
            /** @var Document $document */
            $document = DB::transaction(function () use ($title, $uploadedFile, $user, $originalFileName, &$storedPath): Document {
                $document = Document::query()->create([
                    'title' => $title,
                    'status' => 'active',
                    'confidentiality' => 'confidential',
                    'owner_user_id' => $user->getKey(),
                    'uploaded_by_user_id' => $user->getKey(),
                    'approved_at' => now('UTC'),
                ]);

                $storedPath = sprintf(
                    'documents/%d/revisions/%d/%s-%s',
                    $document->getKey(),
                    1,
                    Str::uuid()->toString(),
                    $originalFileName,
                );

                $stored = Storage::disk('local')->putFileAs(
                    dirname($storedPath),
                    $uploadedFile,
                    basename($storedPath),
                );

                if ($stored === false) {
                    throw new RuntimeException('The document file could not be stored.');
                }

                $revision = DocumentRevision::query()->create([
                    'document_id' => $document->getKey(),
                    'parent_revision_id' => null,
                    'created_by_user_id' => $user->getKey(),
                    'revision_number' => 1,
                    'storage_disk' => 'local',
                    'storage_path' => $storedPath,
                    'original_file_name' => $originalFileName,
                    'mime_type' => $uploadedFile->getClientMimeType(),
                    'size_bytes' => (int) $uploadedFile->getSize(),
                    'sha256' => hash_file('sha256', $uploadedFile->getRealPath()),
                    'signature_status' => 'unsigned',
                    'diff_metadata' => [
                        'kind' => 'initial_upload',
                    ],
                ]);

                $document->forceFill([
                    'current_revision_id' => $revision->getKey(),
                ])->save();

                return $document->fresh(['owner', 'uploadedBy', 'currentRevision']);
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentCreated,
            $user,
            [
                'type' => 'document',
                'id' => $document->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $document->currentRevision?->getKey(),
            ],
            [
                'originalFileName' => $originalFileName,
                'revisionNumber' => $document->currentRevision?->revision_number,
                'title' => $document->title,
            ],
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'document' => [
                'id' => $document->getKey(),
                'title' => $document->title,
                'status' => $document->status,
                'confidentiality' => $document->confidentiality,
                'owner' => [
                    'id' => $document->owner?->getKey(),
                    'name' => $document->owner?->name,
                    'email' => $document->owner?->email,
                ],
                'uploadedBy' => [
                    'id' => $document->uploadedBy?->getKey(),
                    'name' => $document->uploadedBy?->name,
                    'email' => $document->uploadedBy?->email,
                ],
                'currentRevision' => [
                    'id' => $document->currentRevision?->getKey(),
                    'revisionNumber' => $document->currentRevision?->revision_number,
                    'originalFileName' => $document->currentRevision?->original_file_name,
                    'mimeType' => $document->currentRevision?->mime_type,
                    'sizeBytes' => $document->currentRevision?->size_bytes,
                    'sha256' => $document->currentRevision?->sha256,
                    'signatureStatus' => $document->currentRevision?->signature_status,
                ],
                'createdAt' => $document->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
