<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\VerificationPackageSigningKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VerificationPackageSigningKeyRotationOptionsController extends Controller
{
    public const INTENT_KEY = 'admin.verification_package_signing_key.rotation.webauthn.intent';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly VerificationPackageSigningKeyService $signingKeyService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bits' => ['nullable', 'integer', Rule::in([2048, 3072, 4096])],
            'keyId' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $summary = $this->signingKeyService->summary();

        if (! (bool) $summary['rotationSupported']) {
            return response()->json([
                'message' => 'Package signing key rotation is not supported because .env is not writable by the app process.',
                'error' => [
                    'code' => 'package_signing_env_not_writable',
                ],
            ], 422);
        }

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json([
                'message' => 'WebAuthn package-signing rotation origin is invalid.',
            ], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Package signing key rotation requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for package signing key rotation.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $bits = (int) ($validated['bits'] ?? 3072);
        $reason = trim((string) $validated['reason']);
        $keyId = trim((string) $validated['keyId']);

        $intent = [
            'version' => 1,
            'purpose' => 'admin-package-signing-key-rotation',
            'actorUserId' => (int) $actor->getKey(),
            'previousKeyId' => $summary['keyId'],
            'previousPublicKeyFingerprint' => $summary['publicKeyFingerprint'],
            'targetKeyId' => $keyId,
            'bits' => $bits,
            'reason' => $reason,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];

        $request->session()->put(self::INTENT_KEY, $intent);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AdminPackageSigningKeyRotationChallengeStarted,
            $actor,
            ['type' => 'package_signing_key'],
            [
                'previousKeyId' => $summary['keyId'],
                'previousPublicKeyFingerprint' => $summary['publicKeyFingerprint'],
                'targetKeyId' => $keyId,
                'bits' => $bits,
                'reason' => $reason,
                'rpId' => $originHost,
            ],
        );

        return response()->json([
            'message' => 'Package signing key rotation authentication challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rpId' => $originHost,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials
                    ->map(fn ($credential) => [
                        'id' => $credential->credential_id,
                        'type' => 'public-key',
                        'transports' => $credential->transports,
                    ])
                    ->values(),
            ],
            'rotation' => [
                'bits' => $bits,
                'expiresAt' => $expiresAt->toIso8601String(),
                'previousKeyId' => $summary['keyId'],
                'previousPublicKeyFingerprint' => $summary['publicKeyFingerprint'],
                'targetKeyId' => $keyId,
            ],
        ]);
    }
}
