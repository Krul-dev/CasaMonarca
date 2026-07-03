<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\VerificationPackageSigningKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VerificationPackageSigningKeyRotationVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly VerificationPackageSigningKeyService $signingKeyService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $pendingIntent = $request->session()->get(VerificationPackageSigningKeyRotationOptionsController::INTENT_KEY);

        if (
            ! is_array($pendingIntent) ||
            ! is_numeric($pendingIntent['version'] ?? null) ||
            ! is_string($pendingIntent['purpose'] ?? null) ||
            ! is_numeric($pendingIntent['actorUserId'] ?? null) ||
            ! is_string($pendingIntent['targetKeyId'] ?? null) ||
            ! is_numeric($pendingIntent['bits'] ?? null) ||
            ! is_string($pendingIntent['reason'] ?? null) ||
            ! is_string($pendingIntent['challenge'] ?? null) ||
            ! is_string($pendingIntent['origin'] ?? null) ||
            ! is_string($pendingIntent['rpId'] ?? null) ||
            ! is_string($pendingIntent['expiresAt'] ?? null)
        ) {
            return response()->json([
                'message' => 'Package signing key rotation authentication challenge was not initiated.',
            ], 401);
        }

        if ((int) $pendingIntent['version'] !== 1 || $pendingIntent['purpose'] !== 'admin-package-signing-key-rotation') {
            return response()->json([
                'message' => 'Package signing key rotation authentication challenge is invalid.',
            ], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $pendingIntent['expiresAt']);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Package signing key rotation authentication challenge is invalid.',
            ], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json([
                'message' => 'Package signing key rotation authentication challenge expired. Request a new challenge.',
            ], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if ($actor === null || (int) $actor->getKey() !== (int) $pendingIntent['actorUserId']) {
            return response()->json([
                'message' => 'Package signing key rotation authentication challenge does not match the authenticated session.',
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

        $credential = $actor->webauthnCredentials()
            ->where('credential_id', (string) $payload['id'])
            ->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages([
                'id' => ['This security key is not registered to the current admin account.'],
            ]);
        }

        $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
            $payload,
            $credential,
            (string) $pendingIntent['challenge'],
            (string) $pendingIntent['origin'],
            (string) $pendingIntent['rpId'],
        );

        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();

        $rotation = $this->signingKeyService->rotate(
            (string) $pendingIntent['targetKeyId'],
            (int) $pendingIntent['bits'],
        );
        $this->forgetChallenge($request);

        $this->auditEventService->success(
            $request,
            AuditEventType::AdminPackageSigningKeyRotated,
            $actor,
            ['type' => 'package_signing_key'],
            [
                'previousKeyId' => data_get($rotation, 'previous.keyId'),
                'previousPublicKeyFingerprint' => data_get($rotation, 'previous.publicKeyFingerprint'),
                'newKeyId' => data_get($rotation, 'current.keyId'),
                'newPublicKeyFingerprint' => data_get($rotation, 'current.publicKeyFingerprint'),
                'bits' => data_get($rotation, 'bits'),
                'reason' => trim((string) $pendingIntent['reason']),
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'signCount' => $newSignCount,
            ],
        );

        return response()->json([
            'message' => 'Verification package signing key rotated successfully.',
            'signingKey' => $rotation['current'],
            'previousSigningKey' => $rotation['previous'],
        ]);
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget(VerificationPackageSigningKeyRotationOptionsController::INTENT_KEY);
    }
}
