<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentTombstone;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Security\SecurityChallengeIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DocumentDeleteVerifyController extends Controller
{
    private const CHALLENGE_KEY = 'documents.delete.webauthn.challenge';

    private const ORIGIN_KEY = 'documents.delete.webauthn.origin';

    private const RP_ID_KEY = 'documents.delete.webauthn.rp_id';

    private const USER_ID_KEY = 'documents.delete.webauthn.user_id';

    private const DOCUMENT_ID_KEY = 'documents.delete.webauthn.document_id';

    private const CHALLENGE_INTENT_ID_KEY = 'documents.delete.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $pendingChallenge = $request->session()->get(self::CHALLENGE_KEY);
        $pendingOrigin = $request->session()->get(self::ORIGIN_KEY);
        $pendingRpId = $request->session()->get(self::RP_ID_KEY);
        $pendingUserId = $request->session()->get(self::USER_ID_KEY);
        $pendingDocumentId = $request->session()->get(self::DOCUMENT_ID_KEY);
        $pendingChallengeIntentId = $request->session()->get(self::CHALLENGE_INTENT_ID_KEY);

        if (
            ! is_string($pendingChallenge) ||
            ! is_string($pendingOrigin) ||
            ! is_string($pendingRpId) ||
            ! is_numeric($pendingUserId) ||
            ! is_numeric($pendingDocumentId)
        ) {
            return response()->json([
                'message' => 'Document deletion challenge was not initiated.',
            ], 401);
        }

        /** @var User|null $user */
        $user = $request->user();
        $challengeIntent = null;

        if ($user === null || (int) $user->getKey() !== (int) $pendingUserId) {
            return response()->json([
                'message' => 'Document deletion challenge does not match the authenticated session.',
            ], 401);
        }

        if (is_string($pendingChallengeIntentId) && $pendingChallengeIntentId !== '') {
            $challengeIntent = $this->securityChallengeIntentService->findPendingForActor(
                $pendingChallengeIntentId,
                $user,
                'document.delete',
            );

            if (! $challengeIntent instanceof SecurityChallengeIntent) {
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document deletion challenge is no longer pending.',
                ], 401);
            }

            if ($challengeIntent->expires_at?->isPast()) {
                $this->securityChallengeIntentService->markExpired($challengeIntent, $request);
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document deletion challenge expired. Request a new deletion challenge.',
                ], 401);
            }

            if (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge($pendingChallenge)) ||
                (int) data_get($challengeIntent->payload, 'documentId') !== (int) $pendingDocumentId
            ) {
                $this->securityChallengeIntentService->markFailed($challengeIntent, 'intent_payload_mismatch');
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document deletion challenge is invalid.',
                ], 401);
            }
        }

        if (! $this->documentAuthorizationService->canDeleteDocument($user)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.delete', $document);
        }

        if ((int) $document->getKey() !== (int) $pendingDocumentId) {
            $this->markChallengeFailed($challengeIntent, 'document_mismatch');

            return response()->json([
                'message' => 'Document deletion challenge does not match the selected document.',
            ], 401);
        }

        $payload = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
            'response.userHandle' => ['nullable', 'string'],
        ]);

        $credential = $user->webauthnCredentials()
            ->where('credential_id', (string) $payload['id'])
            ->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages([
                'id' => ['This security key is not registered to the current account.'],
            ]);
        }

        try {
            $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
                $payload,
                $credential,
                $pendingChallenge,
                $pendingOrigin,
                $pendingRpId,
            );
        } catch (ValidationException $exception) {
            $this->markChallengeFailed($challengeIntent, 'assertion_validation_failed');

            throw $exception;
        }

        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();

        $document->load(['currentRevision', 'revisions']);

        $revisionCount = $document->revisions->count();
        $lastSha256 = $document->currentRevision?->sha256;
        $storagePaths = $document->revisions
            ->map(fn ($revision): array => [
                'disk' => $revision->storage_disk,
                'path' => $revision->storage_path,
            ])
            ->values()
            ->all();

        $tombstone = DB::transaction(function () use ($document, $user, $revisionCount, $lastSha256): DocumentTombstone {
            $tombstone = DocumentTombstone::query()->create([
                'original_document_id' => $document->getKey(),
                'title' => $document->title,
                'deleted_by_user_id' => $user->getKey(),
                'deleted_at' => now(),
                'last_sha256' => $lastSha256,
                'revision_count' => $revisionCount,
                'metadata' => [
                    'uploadedByUserId' => $document->uploaded_by_user_id,
                    'ownerUserId' => $document->owner_user_id,
                ],
            ]);

            $document->delete();

            return $tombstone;
        });

        foreach ($storagePaths as $storageReference) {
            $disk = (string) ($storageReference['disk'] ?? 'local');
            $path = (string) ($storageReference['path'] ?? '');

            if ($path === '') {
                continue;
            }

            if (! Storage::disk($disk)->exists($path)) {
                continue;
            }

            if (! Storage::disk($disk)->delete($path)) {
                report(new \RuntimeException(sprintf(
                    'Document payload cleanup failed for deleted document %d at %s:%s',
                    $tombstone->original_document_id,
                    $disk,
                    $path,
                )));
            }
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentDeleted,
            $user,
            [
                'type' => 'document',
                'id' => $tombstone->original_document_id,
                'documentId' => $tombstone->original_document_id,
            ],
            [
                'challengeIntentId' => $challengeIntent?->getKey(),
                'lastSha256' => $tombstone->last_sha256,
                'revisionCount' => $tombstone->revision_count,
                'tombstoneId' => $tombstone->getKey(),
            ],
        );

        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }

        $this->forgetChallenge($request);

        return response()->json([
            'message' => 'Document deleted permanently.',
            'tombstone' => [
                'id' => $tombstone->getKey(),
                'originalDocumentId' => $tombstone->original_document_id,
                'deletedAt' => $tombstone->deleted_at?->toIso8601String(),
                'lastSha256' => $tombstone->last_sha256,
                'revisionCount' => $tombstone->revision_count,
            ],
        ]);
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            self::CHALLENGE_KEY,
            self::ORIGIN_KEY,
            self::RP_ID_KEY,
            self::USER_ID_KEY,
            self::DOCUMENT_ID_KEY,
            self::CHALLENGE_INTENT_ID_KEY,
        ]);
        $request->session()->regenerateToken();
    }

    private function markChallengeFailed(?SecurityChallengeIntent $challengeIntent, string $reason): void
    {
        if ($challengeIntent instanceof SecurityChallengeIntent && $challengeIntent->isPending()) {
            $this->securityChallengeIntentService->markFailed($challengeIntent, $reason);
        }
    }
}
