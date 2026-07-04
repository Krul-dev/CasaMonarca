<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentRevisionUpdateIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class DocumentRevisionUpdateVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentRevisionUpdateIntentService $documentRevisionUpdateIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $pendingIntent = $request->session()->get(
            DocumentRevisionUpdateOptionsController::REVISION_UPDATE_INTENT_KEY,
        );

        if (! $this->isValidIntent($pendingIntent)) {
            return response()->json([
                'message' => 'Document revision update challenge was not initiated.',
            ], 401);
        }

        /** @var array<string, int|string> $pendingIntent */
        $pendingDocumentId = (int) $pendingIntent['documentId'];
        $pendingRevisionId = (int) $pendingIntent['revisionId'];
        $pendingRevisionNumber = (int) $pendingIntent['revisionNumber'];
        $pendingRevisionHash = (string) $pendingIntent['revisionSha256'];
        $pendingUserId = (int) $pendingIntent['userId'];
        $pendingOrigin = (string) $pendingIntent['origin'];
        $pendingRpId = (string) $pendingIntent['rpId'];
        $pendingCandidateFileName = (string) $pendingIntent['candidateOriginalFileName'];
        $pendingCandidateHash = (string) $pendingIntent['candidateSha256'];
        $pendingCandidateSize = (int) $pendingIntent['candidateSizeBytes'];

        if (
            (int) $pendingIntent['version'] !== 1 ||
            (string) $pendingIntent['purpose'] !== 'document-revision-update'
        ) {
            return response()->json([
                'message' => 'Document revision update challenge is invalid.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $pendingIntent['expiresAt']);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Document revision update challenge is invalid.',
            ], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Document revision update challenge expired. Request a new update challenge.',
            ], 401);
        }

        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || (int) $user->getKey() !== $pendingUserId) {
            return response()->json([
                'message' => 'Document revision update challenge does not match the authenticated session.',
            ], 401);
        }

        if (! $document->isApproved() || ! $this->documentAuthorizationService->canUpdateDocument($user)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.update', $document);
        }

        if ((int) $document->getKey() !== $pendingDocumentId) {
            return response()->json([
                'message' => 'Document revision update challenge does not match the selected document.',
            ], 401);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:16384'],
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
            'response.userHandle' => ['nullable', 'string'],
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $validated['file'];
        $originalFileName = basename($uploadedFile->getClientOriginalName());
        $fileSize = (int) $uploadedFile->getSize();
        $fileHash = hash_file('sha256', $uploadedFile->getRealPath());

        if (
            ! is_string($fileHash) ||
            $originalFileName !== $pendingCandidateFileName ||
            $fileSize !== $pendingCandidateSize ||
            ! hash_equals($pendingCandidateHash, $fileHash)
        ) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Uploaded file does not match the passkey-bound revision target.',
            ], 422);
        }

        $document->load('currentRevision');
        $revision = $document->currentRevision;
        abort_unless($revision !== null, 404, 'Current document revision could not be found.');

        if (
            (int) $revision->getKey() !== $pendingRevisionId ||
            (int) $revision->revision_number !== $pendingRevisionNumber ||
            ! hash_equals($pendingRevisionHash, (string) $revision->sha256)
        ) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Document revision update challenge no longer matches the current revision. Reload the document and try again.',
            ], 409);
        }

        $credential = $user->webauthnCredentials()
            ->where('credential_id', (string) $validated['id'])
            ->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages([
                'id' => ['This security key is not registered to the current account.'],
            ]);
        }

        $pendingChallenge = $this->documentRevisionUpdateIntentService->deriveChallenge($pendingIntent);
        $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
            $validated,
            $credential,
            $pendingChallenge,
            $pendingOrigin,
            $pendingRpId,
        );

        $storedPath = null;

        try {
            /** @var Document $updatedDocument */
            $updatedDocument = DB::transaction(function () use (
                $document,
                $credential,
                $fileHash,
                $fileSize,
                $newSignCount,
                $originalFileName,
                $pendingChallenge,
                $pendingIntent,
                $pendingRevisionHash,
                $pendingRevisionId,
                $pendingRevisionNumber,
                $uploadedFile,
                $user,
                &$storedPath,
            ): Document {
                $lockedDocument = Document::query()
                    ->whereKey($document->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedDocument->load('currentRevision');

                $currentRevision = $lockedDocument->currentRevision;

                if (
                    ! $currentRevision instanceof DocumentRevision ||
                    (int) $currentRevision->getKey() !== $pendingRevisionId ||
                    (int) $currentRevision->revision_number !== $pendingRevisionNumber ||
                    ! hash_equals($pendingRevisionHash, (string) $currentRevision->sha256)
                ) {
                    throw new RuntimeException('Document revision changed during the update.');
                }

                $nextRevisionNumber = (int) $currentRevision->revision_number + 1;
                $storedPath = sprintf(
                    'documents/%d/revisions/%d/%s-%s',
                    $lockedDocument->getKey(),
                    $nextRevisionNumber,
                    Str::uuid()->toString(),
                    $originalFileName,
                );

                $stored = Storage::disk('local')->putFileAs(
                    dirname($storedPath),
                    $uploadedFile,
                    basename($storedPath),
                );

                if ($stored === false) {
                    throw new RuntimeException('The revision file could not be stored.');
                }

                $revision = DocumentRevision::query()->create([
                    'document_id' => $lockedDocument->getKey(),
                    'parent_revision_id' => $currentRevision->getKey(),
                    'created_by_user_id' => $user->getKey(),
                    'revision_number' => $nextRevisionNumber,
                    'storage_disk' => 'local',
                    'storage_path' => $storedPath,
                    'original_file_name' => $originalFileName,
                    'mime_type' => $uploadedFile->getClientMimeType(),
                    'size_bytes' => $fileSize,
                    'sha256' => $fileHash,
                    'signature_status' => 'unsigned',
                    'diff_metadata' => [
                        'kind' => 'revision_update',
                        'parentRevisionId' => $currentRevision->getKey(),
                        'parentRevisionNumber' => $currentRevision->revision_number,
                        'parentSha256' => $currentRevision->sha256,
                        'challenge' => $pendingChallenge,
                        'canonicalIntent' => $this->documentRevisionUpdateIntentService->toCanonicalJson($pendingIntent),
                        'credentialId' => $credential->credential_id,
                    ],
                ]);

                $lockedDocument->forceFill([
                    'current_revision_id' => $revision->getKey(),
                ])->save();

                $credential->forceFill([
                    'sign_count' => $newSignCount,
                    'last_used_at' => now(),
                ])->save();

                return $lockedDocument->fresh(['owner', 'uploadedBy', 'currentRevision']);
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            if ($exception instanceof RuntimeException && $exception->getMessage() === 'Document revision changed during the update.') {
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document revision update challenge no longer matches the current revision. Reload the document and try again.',
                ], 409);
            }

            throw $exception;
        }

        $this->forgetChallenge($request);

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentRevisionCreated,
            $user,
            [
                'type' => 'document_revision',
                'id' => $updatedDocument->currentRevision?->getKey(),
                'documentId' => $updatedDocument->getKey(),
                'revisionId' => $updatedDocument->currentRevision?->getKey(),
            ],
            [
                'originalFileName' => $updatedDocument->currentRevision?->original_file_name,
                'parentRevisionId' => $pendingRevisionId,
                'revisionNumber' => $updatedDocument->currentRevision?->revision_number,
            ],
        );

        return response()->json([
            'message' => 'Document revision uploaded successfully.',
            'document' => [
                'id' => $updatedDocument->getKey(),
                'title' => $updatedDocument->title,
                'status' => $updatedDocument->status,
                'confidentiality' => $updatedDocument->confidentiality,
                'owner' => [
                    'id' => $updatedDocument->owner?->getKey(),
                    'name' => $updatedDocument->owner?->name,
                    'email' => $updatedDocument->owner?->email,
                ],
                'uploadedBy' => [
                    'id' => $updatedDocument->uploadedBy?->getKey(),
                    'name' => $updatedDocument->uploadedBy?->name,
                    'email' => $updatedDocument->uploadedBy?->email,
                ],
                'currentRevision' => [
                    'id' => $updatedDocument->currentRevision?->getKey(),
                    'revisionNumber' => $updatedDocument->currentRevision?->revision_number,
                    'originalFileName' => $updatedDocument->currentRevision?->original_file_name,
                    'mimeType' => $updatedDocument->currentRevision?->mime_type,
                    'sizeBytes' => $updatedDocument->currentRevision?->size_bytes,
                    'sha256' => $updatedDocument->currentRevision?->sha256,
                    'signatureStatus' => $updatedDocument->currentRevision?->signature_status,
                ],
                'updatedAt' => $updatedDocument->updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            DocumentRevisionUpdateOptionsController::REVISION_UPDATE_INTENT_KEY,
        ]);
        $request->session()->regenerateToken();
    }

    private function isValidIntent(mixed $pendingIntent): bool
    {
        return is_array($pendingIntent) &&
            is_numeric($pendingIntent['version'] ?? null) &&
            is_string($pendingIntent['purpose'] ?? null) &&
            is_numeric($pendingIntent['documentId'] ?? null) &&
            is_numeric($pendingIntent['revisionId'] ?? null) &&
            is_numeric($pendingIntent['revisionNumber'] ?? null) &&
            is_string($pendingIntent['revisionSha256'] ?? null) &&
            is_numeric($pendingIntent['userId'] ?? null) &&
            is_string($pendingIntent['origin'] ?? null) &&
            is_string($pendingIntent['rpId'] ?? null) &&
            is_string($pendingIntent['issuedAt'] ?? null) &&
            is_string($pendingIntent['expiresAt'] ?? null) &&
            is_string($pendingIntent['nonce'] ?? null) &&
            is_string($pendingIntent['candidateOriginalFileName'] ?? null) &&
            is_string($pendingIntent['candidateSha256'] ?? null) &&
            is_numeric($pendingIntent['candidateSizeBytes'] ?? null);
    }
}
