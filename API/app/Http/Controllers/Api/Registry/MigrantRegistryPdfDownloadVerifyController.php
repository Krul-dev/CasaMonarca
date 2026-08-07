<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryPdfService;
use App\Services\Registry\MigrantRegistryService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class MigrantRegistryPdfDownloadVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly MigrantRegistryPdfService $pdfService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /** @throws ValidationException */
    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse|Response
    {
        $intent = $request->session()->get(MigrantRegistryPdfDownloadOptionsController::INTENT_KEY);

        if (! is_array($intent) || ($intent['purpose'] ?? null) !== 'migrant-registry-pdf-download') {
            return response()->json(['message' => 'Registry PDF download challenge was not initiated.'], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) ($intent['expiresAt'] ?? ''));
        } catch (\Throwable) {
            return response()->json(['message' => 'Registry PDF download challenge is invalid.'], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Registry PDF download challenge expired. Request a new challenge.'], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if (
            ! $actor instanceof User ||
            $actor->role !== UserRole::Admin ||
            (int) $actor->getKey() !== (int) ($intent['actorUserId'] ?? 0)
        ) {
            return response()->json(['message' => 'Registry PDF download challenge does not match the authenticated session.'], 401);
        }

        $challengeIntent = $this->pendingChallengeIntent($request, $actor);

        if ($challengeIntent instanceof JsonResponse) {
            return $challengeIntent;
        }

        if (
            $migrantRegistryEntry->current_status === MigrantRegistryService::STATUS_DRAFT ||
            (int) $migrantRegistryEntry->getKey() !== (int) ($intent['entryId'] ?? 0) ||
            ! is_string($intent['registryStateHash'] ?? null) ||
            ! hash_equals($intent['registryStateHash'], $this->pdfService->stateHash($migrantRegistryEntry))
        ) {
            $this->failChallenge($challengeIntent, 'registry_state_changed');
            $this->forgetChallenge($request);

            return response()->json(['message' => 'The migrant registration changed after authentication started. Reload and try again.'], 409);
        }

        if (
            $challengeIntent instanceof SecurityChallengeIntent &&
            (
                ! hash_equals($challengeIntent->challenge_hash, $this->securityChallengeIntentService->hashChallenge((string) ($intent['challenge'] ?? ''))) ||
                (int) data_get($challengeIntent->payload, 'entryId') !== (int) $intent['entryId']
            )
        ) {
            $this->failChallenge($challengeIntent, 'intent_payload_mismatch');
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Registry PDF download challenge is invalid.'], 401);
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

        $pdf = $this->pdfService->render($migrantRegistryEntry);
        $this->auditEventService->success(
            $request,
            AuditEventType::MigrantRegistryPdfDownloaded,
            $actor,
            ['type' => MigrantRegistryEntry::class, 'id' => $migrantRegistryEntry->getKey()],
            [
                'credentialId' => $credential->credential_id,
                'challengeIntentId' => $challengeIntent?->getKey(),
                'registryStateHash' => $intent['registryStateHash'],
            ],
        );
        $this->forgetChallenge($request);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="registro-migrante-'.$migrantRegistryEntry->getKey().'.pdf"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function pendingChallengeIntent(Request $request, User $actor): SecurityChallengeIntent|JsonResponse|null
    {
        $id = $request->session()->get(MigrantRegistryPdfDownloadOptionsController::CHALLENGE_INTENT_ID_KEY);

        if (! is_string($id) || $id === '') {
            return null;
        }

        $intent = $this->securityChallengeIntentService->findPendingForActor($id, $actor, 'migrant.registry.pdf.download');

        if (! $intent instanceof SecurityChallengeIntent) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Registry PDF download challenge is no longer pending.'], 401);
        }

        if ($intent->expires_at?->isPast()) {
            $this->securityChallengeIntentService->markExpired($intent, $request);
            $this->forgetChallenge($request);

            return response()->json(['message' => 'Registry PDF download challenge expired. Request a new challenge.'], 401);
        }

        return $intent;
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
            MigrantRegistryPdfDownloadOptionsController::INTENT_KEY,
            MigrantRegistryPdfDownloadOptionsController::CHALLENGE_INTENT_ID_KEY,
        ]);
        $request->session()->regenerateToken();
    }
}
