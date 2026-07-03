<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignature;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentSignatureExpiryService;
use App\Services\Documents\DocumentSignatureRequirementService;
use App\Services\Documents\DocumentSigningIntentService;
use App\Services\Documents\DocumentSignatureViewService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentSignVerifyController extends Controller
{
    private const SIGN_INTENT_KEY = 'documents.sign.webauthn.intent';

    private const SIGN_CHALLENGE_INTENT_ID_KEY = 'documents.sign.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentSignatureExpiryService $documentSignatureExpiryService,
        private readonly DocumentSignatureRequirementService $documentSignatureRequirementService,
        private readonly DocumentSigningIntentService $documentSigningIntentService,
        private readonly DocumentSignatureViewService $documentSignatureViewService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, Document $document, ?DocumentRevision $revision = null): JsonResponse
    {
        $pendingIntent = $request->session()->get(self::SIGN_INTENT_KEY);

        if (
            ! is_array($pendingIntent) ||
            ! is_numeric($pendingIntent['version'] ?? null) ||
            ! is_string($pendingIntent['purpose'] ?? null) ||
            ! is_numeric($pendingIntent['documentId'] ?? null) ||
            ! is_numeric($pendingIntent['revisionId'] ?? null) ||
            ! is_numeric($pendingIntent['revisionNumber'] ?? null) ||
            ! is_string($pendingIntent['revisionSha256'] ?? null) ||
            ! is_numeric($pendingIntent['userId'] ?? null) ||
            ! is_string($pendingIntent['origin'] ?? null) ||
            ! is_string($pendingIntent['rpId'] ?? null) ||
            ! is_string($pendingIntent['issuedAt'] ?? null) ||
            ! is_string($pendingIntent['expiresAt'] ?? null) ||
            ! is_string($pendingIntent['nonce'] ?? null)
        ) {
            return response()->json([
                'message' => 'Document signature challenge was not initiated.',
            ], 401);
        }

        $pendingVersion = (int) $pendingIntent['version'];
        $pendingPurpose = (string) $pendingIntent['purpose'];
        $pendingDocumentId = (int) $pendingIntent['documentId'];
        $pendingRevisionId = (int) $pendingIntent['revisionId'];
        $pendingRevisionNumber = (int) $pendingIntent['revisionNumber'];
        $pendingRevisionHash = (string) $pendingIntent['revisionSha256'];
        $pendingUserId = (int) $pendingIntent['userId'];
        $pendingOrigin = (string) $pendingIntent['origin'];
        $pendingRpId = (string) $pendingIntent['rpId'];
        $pendingExpiresAt = (string) $pendingIntent['expiresAt'];

        if ($pendingVersion !== 1 || $pendingPurpose !== 'document-sign') {
            return response()->json([
                'message' => 'Document signature challenge is invalid.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse($pendingExpiresAt);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Document signature challenge is invalid.',
            ], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Document signature challenge expired. Request a new signature challenge.',
            ], 401);
        }

        $pendingChallenge = $this->documentSigningIntentService->deriveChallenge($pendingIntent);

        /** @var User|null $user */
        $user = $request->user();
        $challengeIntent = null;

        if ($user === null || (int) $user->getKey() !== $pendingUserId) {
            return response()->json([
                'message' => 'Document signature challenge does not match the authenticated session.',
            ], 401);
        }

        $challengeIntentId = $request->session()->get(self::SIGN_CHALLENGE_INTENT_ID_KEY);

        if (is_string($challengeIntentId) && $challengeIntentId !== '') {
            $challengeIntent = $this->securityChallengeIntentService->findPendingForActor(
                $challengeIntentId,
                $user,
                'document.sign',
            );

            if (! $challengeIntent instanceof SecurityChallengeIntent) {
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document signature challenge is no longer pending.',
                ], 401);
            }

            if ($challengeIntent->expires_at?->isPast()) {
                $this->securityChallengeIntentService->markExpired($challengeIntent, $request);
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document signature challenge expired. Request a new signature challenge.',
                ], 401);
            }

            if (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge($pendingChallenge)) ||
                (int) data_get($challengeIntent->payload, 'documentId') !== $pendingDocumentId ||
                (int) data_get($challengeIntent->payload, 'revisionId') !== $pendingRevisionId ||
                ! hash_equals((string) data_get($challengeIntent->payload, 'revisionSha256'), $pendingRevisionHash)
            ) {
                $this->securityChallengeIntentService->markFailed($challengeIntent, 'intent_payload_mismatch');
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document signature challenge is invalid.',
                ], 401);
            }
        }

        if ((int) $document->getKey() !== $pendingDocumentId) {
            $this->markChallengeFailed($challengeIntent, 'document_mismatch');

            return response()->json([
                'message' => 'Document signature challenge does not match the selected document.',
            ], 401);
        }

        if ($revision !== null) {
            abort_unless(
                (int) $revision->document_id === (int) $document->getKey(),
                404,
                'Selected document revision could not be found.',
            );

            if ((int) $revision->getKey() !== $pendingRevisionId) {
                $this->markChallengeFailed($challengeIntent, 'revision_mismatch');

                return response()->json([
                    'message' => 'Document signature challenge does not match the selected revision.',
                ], 401);
            }

            $revision->load('signatures');
        } else {
            $document->load('currentRevision.signatures');
            $revision = $document->currentRevision;
            abort_unless($revision !== null, 404, 'Current document revision could not be found.');

            if ((int) $revision->getKey() !== $pendingRevisionId) {
                $this->markChallengeFailed($challengeIntent, 'current_revision_changed');
                $this->forgetChallenge($request);

                return response()->json([
                    'message' => 'Document signature challenge no longer matches the current revision. Reload the document and sign again.',
                ], 409);
            }
        }

        if (
            (int) $revision->revision_number !== $pendingRevisionNumber ||
            ! hash_equals($pendingRevisionHash, (string) $revision->sha256)
        ) {
            $this->markChallengeFailed($challengeIntent, 'revision_hash_mismatch');
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Document signature challenge no longer matches the selected revision. Reload the document and sign again.',
            ], 409);
        }

        if (! $this->documentAuthorizationService->canSignRevision($user, $document, $revision)) {
            return $this->documentAuthorizationService->forbiddenResponse($request, $user, 'document.sign', $document, $revision);
        }

        if ($revision->signatures()->where('signed_by_user_id', $user->getKey())->exists()) {
            $this->markChallengeFailed($challengeIntent, 'already_signed');

            return response()->json([
                'message' => 'This revision is already signed by this account.',
            ], 409);
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

        $signedAt = CarbonImmutable::now('UTC');

        $signature = $revision->signatures()->create([
            'signed_by_user_id' => $user->getKey(),
            'signature_type' => 'passkey',
            'verification_status' => 'verified',
            'signed_at' => $signedAt,
            'signature_hash' => $revision->sha256,
            'metadata' => [
                'credentialId' => $credential->credential_id,
                'credentialName' => $credential->name,
                'publicKey' => $credential->public_key,
                'publicKeyFormat' => 'spki-der-base64url',
                'publicKeyAlgorithm' => (int) $credential->public_key_algorithm,
                'publicKeyFingerprintSha256' => hash(
                    'sha256',
                    $this->base64UrlService->decode((string) $credential->public_key),
                ),
                'signCount' => $newSignCount,
                'documentHash' => $revision->sha256,
                'challenge' => $pendingChallenge,
                'intent' => $pendingIntent,
                'canonicalIntent' => $this->documentSigningIntentService->toCanonicalJson($pendingIntent),
                'validity' => $this->documentSignatureExpiryService->buildValidityMetadata($signedAt),
                'assertion' => [
                    'id' => (string) $payload['id'],
                    'rawId' => (string) $payload['rawId'],
                    'type' => (string) $payload['type'],
                    'response' => [
                        'clientDataJSON' => (string) data_get($payload, 'response.clientDataJSON'),
                        'authenticatorData' => (string) data_get($payload, 'response.authenticatorData'),
                        'signature' => (string) data_get($payload, 'response.signature'),
                        'userHandle' => data_get($payload, 'response.userHandle'),
                    ],
                ],
            ],
        ]);

        $this->documentSignatureRequirementService->fulfillForSignature($document, $user, $signature);
        $document->load('signatureRequirements');

        $allRequiredSignaturesCollected = $document->signatureRequirements->isEmpty()
            || $this->documentSignatureRequirementService->allRequirementsFulfilled($document);

        $revision->forceFill([
            'signature_status' => $allRequiredSignaturesCollected ? 'signed' : 'partially_signed',
        ])->save();

        $revision->load('signatures.signedBy');
        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }
        $this->forgetChallenge($request);

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentSigned,
            $user,
            [
                'type' => 'document_revision',
                'id' => $revision->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $revision->getKey(),
            ],
            [
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'challengeIntentId' => $challengeIntent?->getKey(),
                'expiresAt' => data_get($signature->metadata, 'validity.expiresAt'),
                'revisionNumber' => $revision->revision_number,
                'signatureId' => $signature->getKey(),
                'signCount' => $newSignCount,
            ],
        );

        return response()->json([
            'message' => 'Document signed successfully.',
            'signature' => $this->documentSignatureViewService->toVerificationSignature($signature),
            'verification' => [
                'documentId' => $document->getKey(),
                'currentRevisionId' => $revision->getKey(),
                'currentRevisionNumber' => $revision->revision_number,
                'signatureStatus' => $revision->signature_status,
                'hasSignatures' => $revision->signatures->isNotEmpty(),
                'verified' => $revision->signatures->isNotEmpty()
                    && $revision->signatures->every(
                        fn (DocumentSignature $currentSignature): bool => $currentSignature->verification_status === 'verified',
                    ),
                'signatures' => $revision->signatures
                    ->map(fn (DocumentSignature $currentSignature): array => $this->documentSignatureViewService->toVerificationSignature($currentSignature))
                    ->values(),
            ],
        ]);
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            self::SIGN_INTENT_KEY,
            self::SIGN_CHALLENGE_INTENT_ID_KEY,
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
