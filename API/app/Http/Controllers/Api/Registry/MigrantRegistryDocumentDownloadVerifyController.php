<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MigrantRegistryDocumentDownloadVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly MigrantRegistryDocumentDownloadOptionsController $optionsController,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /** @throws ValidationException */
    public function __invoke(
        Request $request,
        MigrantRegistryEntry $migrantRegistryEntry,
        MigrantRegistryDocument $migrantRegistryDocument,
    ): JsonResponse|StreamedResponse {
        $intent = $request->session()->get(MigrantRegistryDocumentDownloadOptionsController::INTENT_KEY);

        if (! is_array($intent) || ($intent['purpose'] ?? null) !== 'migrant-document-download') {
            return response()->json(['message' => 'Document download challenge was not initiated.'], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) ($intent['expiresAt'] ?? ''));
        } catch (\Throwable) {
            return response()->json(['message' => 'Document download challenge is invalid.'], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Document download challenge expired. Request a new challenge.'], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User || (int) $actor->getKey() !== (int) ($intent['actorUserId'] ?? 0)) {
            return response()->json(['message' => 'Document download challenge does not match the authenticated session.'], 401);
        }

        $challengeIntent = $this->pendingChallengeIntent($request, $actor);

        if ($challengeIntent instanceof JsonResponse) {
            return $challengeIntent;
        }

        if (
            ! $this->canDownload($actor) ||
            $migrantRegistryDocument->registry_entry_id !== $migrantRegistryEntry->getKey() ||
            (int) $migrantRegistryEntry->getKey() !== (int) ($intent['entryId'] ?? 0) ||
            (int) $migrantRegistryDocument->getKey() !== (int) ($intent['documentId'] ?? 0) ||
            ! is_string($intent['documentHash'] ?? null) ||
            ! hash_equals($intent['documentHash'], $this->optionsController->documentHash($migrantRegistryDocument))
        ) {
            $this->failChallenge($challengeIntent, 'document_state_changed');
            $this->forgetChallenge($request);

            return response()->json(['message' => 'The migrant document changed after authentication started. Reload and try again.'], 409);
        }

        $disk = Storage::disk($migrantRegistryDocument->storage_disk ?? 'local');

        if (! $migrantRegistryDocument->storage_path || ! $disk->exists($migrantRegistryDocument->storage_path)) {
            $this->failChallenge($challengeIntent, 'document_unavailable');
            $this->forgetChallenge($request);

            return response()->json(['message' => 'This document is no longer available.'], 410);
        }

        if (
            $challengeIntent instanceof SecurityChallengeIntent &&
            (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge((string) ($intent['challenge'] ?? ''))) ||
                (int) data_get($challengeIntent->payload, 'documentId') !== (int) $intent['documentId']
            )
        ) {
            $this->failChallenge($challengeIntent, 'intent_payload_mismatch');
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Document download challenge is invalid.'], 401);
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
        $credential = $actor->webauthnCredentials()->where('credential_id', (string) $payload['id'])->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages(['id' => ['This security key is not registered to the current account.']]);
        }

        try {
            $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
                $payload,
                $credential,
                (string) $intent['challenge'],
                (string) $intent['origin'],
                (string) $intent['rpId'],
            );
        } catch (ValidationException $exception) {
            $this->failChallenge($challengeIntent, 'assertion_validation_failed');
            $this->forgetChallenge($request);
            throw $exception;
        }

        $credential->forceFill(['sign_count' => $newSignCount, 'last_used_at' => now()])->save();

        if ($challengeIntent instanceof SecurityChallengeIntent) {
            $this->securityChallengeIntentService->markSucceeded($challengeIntent);
        }

        $this->auditEventService->success(
            $request,
            AuditEventType::MigrantDocumentDownloaded,
            $actor,
            ['type' => MigrantRegistryDocument::class, 'id' => $migrantRegistryDocument->getKey()],
            [
                'registryEntryId' => $migrantRegistryEntry->getKey(),
                'originalFileName' => $migrantRegistryDocument->original_file_name,
                'sha256' => $migrantRegistryDocument->sha256,
                'credentialId' => $credential->credential_id,
                'challengeIntentId' => $challengeIntent?->getKey(),
            ],
        );
        $this->forgetChallenge($request);

        return $disk->download(
            $migrantRegistryDocument->storage_path,
            $migrantRegistryDocument->original_file_name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function pendingChallengeIntent(Request $request, User $actor): SecurityChallengeIntent|JsonResponse|null
    {
        $id = $request->session()->get(MigrantRegistryDocumentDownloadOptionsController::CHALLENGE_INTENT_ID_KEY);

        if (! is_string($id) || $id === '') {
            return null;
        }

        $intent = $this->securityChallengeIntentService->findPendingForActor($id, $actor, 'migrant.registry.document.download');

        if (! $intent instanceof SecurityChallengeIntent) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Document download challenge is no longer pending.'], 401);
        }

        if ($intent->expires_at?->isPast()) {
            $this->securityChallengeIntentService->markExpired($intent, $request);
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Document download challenge expired. Request a new challenge.'], 401);
        }

        return $intent;
    }

    private function canDownload(User $actor): bool
    {
        return in_array($actor->role ?? UserRole::default(), [UserRole::Admin, UserRole::Coordinator], true);
    }

    private function failChallenge(?SecurityChallengeIntent $intent, string $reason): void
    {
        if ($intent instanceof SecurityChallengeIntent && $intent->isPending()) {
            $this->securityChallengeIntentService->markFailed($intent, $reason);
        }
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget([
            MigrantRegistryDocumentDownloadOptionsController::INTENT_KEY,
            MigrantRegistryDocumentDownloadOptionsController::CHALLENGE_INTENT_ID_KEY,
        ]);
        $request->session()->regenerateToken();
    }
}
