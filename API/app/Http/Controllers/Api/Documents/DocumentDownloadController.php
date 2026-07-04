<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\DocumentAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentDownloadController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
    ) {}

    public function __invoke(Request $request, Document $document, ?DocumentRevision $revision = null): StreamedResponse|JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($revision !== null) {
            abort_unless(
                (int) $revision->document_id === (int) $document->getKey(),
                404,
                'Selected document revision could not be found.',
            );

            if (! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
                return $this->documentAuthorizationService->forbiddenResponse(
                    $request,
                    $user,
                    'history.read',
                    $document,
                    $revision,
                );
            }
        } else {
            $document->load('currentRevision');

            $revision = $document->currentRevision;

            abort_unless($revision !== null, 404, 'Current document revision could not be found.');

            if (! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
                return $this->documentAuthorizationService->forbiddenResponse(
                    $request,
                    $user,
                    'document.download',
                    $document,
                    $revision,
                );
            }
        }

        $isCurrentRevisionDownload = (int) $revision->getKey() === (int) $document->current_revision_id;

        $this->auditEventService->success(
            $request,
            $isCurrentRevisionDownload
                ? AuditEventType::DocumentDownloaded
                : AuditEventType::DocumentRevisionDownloaded,
            $user,
            [
                'type' => $isCurrentRevisionDownload ? 'document' : 'document_revision',
                'id' => $isCurrentRevisionDownload ? $document->getKey() : $revision->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $revision->getKey(),
            ],
            [
                'originalFileName' => $revision->original_file_name,
                'revisionNumber' => $revision->revision_number,
            ],
        );

        return Storage::disk($revision->storage_disk)->download(
            $revision->storage_path,
            $revision->original_file_name,
            [
                'Content-Type' => $revision->mime_type ?? 'application/octet-stream',
            ],
        );
    }
}
