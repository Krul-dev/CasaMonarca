<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\Base64UrlService;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WebauthnRegistrationVerifyController extends Controller
{
    private const REGISTRATION_CHALLENGE_KEY = 'auth.webauthn.registration_challenge';

    private const REGISTRATION_USER_KEY = 'auth.webauthn.registration_user_id';

    private const REGISTRATION_ORIGIN_KEY = 'auth.webauthn.registration_origin';

    private const REGISTRATION_RP_ID_KEY = 'auth.webauthn.registration_rp_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly WebauthnVerificationService $webauthnVerificationService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $pendingChallenge = $request->session()->get(self::REGISTRATION_CHALLENGE_KEY);
        $pendingUserId = $request->session()->get(self::REGISTRATION_USER_KEY);
        $pendingOrigin = $request->session()->get(self::REGISTRATION_ORIGIN_KEY);
        $pendingRpId = $request->session()->get(self::REGISTRATION_RP_ID_KEY);

        if (
            ! is_string($pendingChallenge) ||
            ! is_numeric($pendingUserId) ||
            ! is_string($pendingOrigin) ||
            ! is_string($pendingRpId)
        ) {
            return response()->json([
                'message' => 'WebAuthn registration challenge was not initiated.',
            ], 401);
        }

        if ((int) $pendingUserId !== (int) $user->getKey()) {
            return response()->json([
                'message' => 'WebAuthn registration challenge does not match the current user.',
            ], 401);
        }

        $payload = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.attestationObject' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'],
            'response.publicKey' => ['required', 'string'],
            'response.publicKeyAlgorithm' => ['required', 'integer'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $clientDataRaw = $this->base64UrlService->decode((string) data_get($payload, 'response.clientDataJSON'));
        $clientData = json_decode($clientDataRaw, true);

        if (! is_array($clientData)) {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['Invalid WebAuthn client data payload.'],
            ]);
        }

        $clientChallenge = (string) ($clientData['challenge'] ?? '');

        if (! hash_equals($pendingChallenge, $clientChallenge)) {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['Registration challenge is invalid or expired.'],
            ]);
        }

        if (($clientData['type'] ?? null) !== 'webauthn.create') {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['WebAuthn response type is invalid.'],
            ]);
        }

        if ($this->isOriginMismatch((string) ($clientData['origin'] ?? ''), $pendingOrigin)) {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['WebAuthn origin does not match this app.'],
            ]);
        }

        $authenticatorDataRaw = $this->base64UrlService->decode(
            (string) data_get($payload, 'response.authenticatorData'),
        );

        if ($authenticatorDataRaw === '') {
            throw ValidationException::withMessages([
                'response.authenticatorData' => ['WebAuthn authenticator data is invalid.'],
            ]);
        }

        try {
            $parsedAuthenticatorData = $this->webauthnVerificationService->parseAuthenticatorData(
                $authenticatorDataRaw,
                true,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'response.authenticatorData' => [$exception->getMessage()],
            ]);
        }

        if (! hash_equals(
            $this->webauthnVerificationService->hashRpId($pendingRpId),
            $parsedAuthenticatorData['rpIdHash'],
        )) {
            throw ValidationException::withMessages([
                'response.authenticatorData' => ['WebAuthn RP ID hash does not match this app.'],
            ]);
        }

        if (! $this->webauthnVerificationService->isUserPresent($parsedAuthenticatorData['flags'])) {
            throw ValidationException::withMessages([
                'response.authenticatorData' => ['WebAuthn user presence flag is not set.'],
            ]);
        }

        $rawCredentialId = (string) ($parsedAuthenticatorData['attestedCredentialId'] ?? '');
        $encodedCredentialId = $this->base64UrlService->encode($rawCredentialId);

        if (! hash_equals($encodedCredentialId, (string) $payload['rawId'])) {
            throw ValidationException::withMessages([
                'rawId' => ['Credential ID does not match authenticator attested data.'],
            ]);
        }

        if (! hash_equals((string) $payload['id'], (string) $payload['rawId'])) {
            throw ValidationException::withMessages([
                'id' => ['Credential ID is inconsistent with raw credential ID.'],
            ]);
        }

        if (WebauthnCredential::query()->where('credential_id', $payload['id'])->exists()) {
            throw ValidationException::withMessages([
                'id' => ['This security key is already registered.'],
            ]);
        }

        $publicKey = (string) data_get($payload, 'response.publicKey');
        $publicKeyRaw = $this->base64UrlService->decode($publicKey);

        if ($publicKeyRaw === '') {
            throw ValidationException::withMessages([
                'response.publicKey' => ['WebAuthn public key is invalid.'],
            ]);
        }

        $publicKeyAlgorithm = (int) data_get($payload, 'response.publicKeyAlgorithm');

        if (! $this->webauthnVerificationService->supportsPublicKeyAlgorithm($publicKeyAlgorithm)) {
            throw ValidationException::withMessages([
                'response.publicKeyAlgorithm' => ['WebAuthn public key algorithm is not supported.'],
            ]);
        }

        $credential = $user->webauthnCredentials()->create([
            'credential_id' => $payload['id'],
            'public_key' => $publicKey,
            'public_key_algorithm' => $publicKeyAlgorithm,
            'name' => $payload['name'] ?? 'Security key '.Str::upper(Str::random(4)),
            'transports' => $payload['transports'] ?? null,
            'attestation_object' => data_get($payload, 'response.attestationObject'),
            'client_data_json' => data_get($payload, 'response.clientDataJSON'),
            'sign_count' => (int) $parsedAuthenticatorData['signCount'],
        ]);

        $request->session()->forget([
            self::REGISTRATION_CHALLENGE_KEY,
            self::REGISTRATION_USER_KEY,
            self::REGISTRATION_ORIGIN_KEY,
            self::REGISTRATION_RP_ID_KEY,
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthPasskeyRegistered,
            $user,
            [
                'type' => 'webauthn_credential',
                'id' => null,
            ],
            [
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'credentialName' => $credential->name,
                'publicKeyAlgorithm' => $credential->public_key_algorithm,
                'signCount' => $credential->sign_count,
                'transportCount' => is_array($credential->transports) ? count($credential->transports) : 0,
            ],
        );

        return response()->json([
            'message' => 'Security key registered successfully.',
            'credential' => [
                'id' => $credential->credential_id,
                'name' => $credential->name,
                'transports' => $credential->transports,
                'createdAt' => $credential->created_at?->toISOString(),
            ],
        ]);
    }

    private function isOriginMismatch(string $origin, string $expectedOrigin): bool
    {
        if ($origin === '') {
            return true;
        }

        return ! hash_equals($expectedOrigin, $origin);
    }
}
