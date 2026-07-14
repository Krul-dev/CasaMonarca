<?php

namespace App\Services\Registry;

use App\Enums\AuditEventType;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MigrantArcoChallengeService
{
    private const INTENT_KEY = 'registry.arco.webauthn.intent';

    private const CHALLENGE_ID_KEY = 'registry.arco.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $audit,
        private readonly Base64UrlService $base64Url,
        private readonly SecurityChallengeIntentService $challengeIntents,
        private readonly WebauthnAssertionService $webauthn,
    ) {}

    /** @param array<string, mixed> $intent */
    public function issue(Request $request, User $actor, string $purpose, array $intent, string $targetType, int $targetId): array
    {
        $origin = $this->webauthn->resolveRequestOrigin($request);
        $rpId = $this->webauthn->resolveOriginHost($origin);

        if ($rpId === null || $this->webauthn->isIpHost($rpId)) {
            abort(422, 'ARCO signatures require localhost or a domain name, not an IP address.');
        }

        $credentials = $actor->webauthnCredentials()->get();
        if ($credentials->isEmpty()) {
            abort(422, 'Register a security key before signing an ARCO action.');
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64Url->encode(random_bytes(32));
        $storedIntent = [
            ...$intent,
            'version' => 1,
            'purpose' => $purpose,
            'actorUserId' => (int) $actor->id,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $rpId,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->challengeIntents->create(
            purpose: 'migrant.arco.'.$purpose,
            challenge: $challenge,
            actor: $actor,
            origin: $origin,
            rpId: $rpId,
            expiresAt: $expiresAt,
            payload: [...$storedIntent, 'challenge' => null, 'challengeRedacted' => true, 'proposedPayload' => null],
            targetType: $targetType,
            targetId: $targetId,
        );
        $request->session()->put([self::INTENT_KEY => $storedIntent, self::CHALLENGE_ID_KEY => $challengeIntent->id]);
        $request->session()->regenerateToken();
        $this->audit->success($request, AuditEventType::MigrantArcoChallengeStarted, $actor, ['type' => $targetType, 'id' => $targetId], ['purpose' => $purpose, 'challengeIntentId' => $challengeIntent->id]);

        return [
            'message' => 'ARCO signature challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rpId' => $rpId,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials->map(fn ($credential): array => ['id' => $credential->credential_id, 'type' => 'public-key', 'transports' => $credential->transports])->values(),
            ],
            'challengeIntent' => ['id' => $challengeIntent->id, 'purpose' => $challengeIntent->purpose, 'status' => $challengeIntent->status, 'expiresAt' => $challengeIntent->expires_at?->toIso8601String()],
        ];
    }

    /** @return array<string, mixed> */
    public function intent(Request $request, string $purpose): array
    {
        $intent = $request->session()->get(self::INTENT_KEY);
        if (! is_array($intent) || ($intent['purpose'] ?? null) !== $purpose) {
            abort(401, 'The ARCO signature challenge was not initiated.');
        }
        try {
            if (CarbonImmutable::parse((string) ($intent['expiresAt'] ?? ''))->isPast()) {
                $this->forget($request);
                abort(401, 'The ARCO signature challenge expired.');
            }
        } catch (\Throwable) {
            $this->forget($request);
            abort(401, 'The ARCO signature challenge is invalid.');
        }

        return $intent;
    }

    /** @return array<string, mixed> @throws ValidationException */
    public function verify(Request $request, User $actor, string $purpose): array
    {
        $intent = $this->intent($request, $purpose);
        if ((int) ($intent['actorUserId'] ?? 0) !== (int) $actor->id) {
            abort(401, 'The ARCO signature challenge does not match this session.');
        }

        $challengeId = $request->session()->get(self::CHALLENGE_ID_KEY);
        $challengeIntent = is_string($challengeId)
            ? $this->challengeIntents->findPendingForActor($challengeId, $actor, 'migrant.arco.'.$purpose)
            : null;
        if (! $challengeIntent instanceof SecurityChallengeIntent) {
            $this->forget($request);
            abort(401, 'The ARCO signature challenge is no longer pending.');
        }

        $payload = $request->validate([
            'id' => ['required', 'string'], 'rawId' => ['required', 'string'], 'type' => ['required', 'in:public-key'],
            'response' => ['required', 'array'], 'response.clientDataJSON' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'], 'response.signature' => ['required', 'string'],
            'response.userHandle' => ['nullable', 'string'],
        ]);
        $credential = $actor->webauthnCredentials()->where('credential_id', (string) $payload['id'])->first();
        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages(['id' => ['This security key is not registered to the current account.']]);
        }

        try {
            $signCount = $this->webauthn->verifyAssertionPayload($payload, $credential, (string) $intent['challenge'], (string) $intent['origin'], (string) $intent['rpId']);
        } catch (\Throwable $exception) {
            $this->challengeIntents->markFailed($challengeIntent, 'assertion_validation_failed');
            $this->forget($request);
            throw $exception;
        }
        $credential->forceFill(['sign_count' => $signCount, 'last_used_at' => now()])->save();
        $this->challengeIntents->markSucceeded($challengeIntent);
        $this->forget($request);

        return [
            'credentialId' => $credential->credential_id,
            'credentialName' => $credential->name,
            'signCount' => $signCount,
            'challengeIntentId' => $challengeIntent->id,
            'intent' => [...$intent, 'challenge' => null, 'challengeRedacted' => true, 'proposedPayload' => null],
            'assertion' => $payload,
        ];
    }

    private function forget(Request $request): void
    {
        $request->session()->forget([self::INTENT_KEY, self::CHALLENGE_ID_KEY]);
        $request->session()->regenerateToken();
    }
}
