<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\AuthenticatedUserViewService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Auth\WebauthnVerificationService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class WebauthnLoginVerifyController extends Controller
{
    private const LOGIN_CHALLENGE_KEY = 'auth.webauthn.login_challenge';

    private const LOGIN_ORIGIN_KEY = 'auth.webauthn.login_origin';

    private const LOGIN_RP_ID_KEY = 'auth.webauthn.login_rp_id';

    private const LOGIN_USER_ID_KEY = 'auth.webauthn.login_user_id';

    private const PENDING_TOTP_USER_KEY = 'auth.pending_totp_user_id';

    public function __construct(
        private readonly AuthenticatedUserViewService $authenticatedUserViewService,
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly BrowserDeviceService $browserDeviceService,
        private readonly WebauthnVerificationService $webauthnVerificationService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): JsonResponse
    {
        $pendingChallenge = $request->session()->get(self::LOGIN_CHALLENGE_KEY);
        $pendingOrigin = $request->session()->get(self::LOGIN_ORIGIN_KEY);
        $pendingRpId = $request->session()->get(self::LOGIN_RP_ID_KEY);
        $pendingUserId = $request->session()->get(self::LOGIN_USER_ID_KEY);

        if (
            ! is_string($pendingChallenge) ||
            ! is_string($pendingOrigin) ||
            ! is_string($pendingRpId)
        ) {
            return response()->json([
                'message' => 'WebAuthn login challenge was not initiated.',
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

        if (! hash_equals((string) $payload['id'], (string) $payload['rawId'])) {
            throw ValidationException::withMessages([
                'rawId' => ['Credential ID is inconsistent with raw credential ID.'],
            ]);
        }

        $credential = WebauthnCredential::query()
            ->where('credential_id', $payload['id'])
            ->first();

        if ($credential === null) {
            throw ValidationException::withMessages([
                'id' => ['This security key is not registered.'],
            ]);
        }

        if (is_numeric($pendingUserId) && (int) $pendingUserId !== (int) $credential->user_id) {
            throw ValidationException::withMessages([
                'id' => ['This security key does not match the selected account.'],
            ]);
        }

        $clientDataRaw = $this->base64UrlService->decode((string) data_get($payload, 'response.clientDataJSON'));
        $clientData = $clientDataRaw !== ''
            ? json_decode($clientDataRaw, true)
            : null;

        if (! is_array($clientData)) {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['Invalid WebAuthn client data payload.'],
            ]);
        }

        if (($clientData['type'] ?? null) !== 'webauthn.get') {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['WebAuthn response type is invalid for authentication.'],
            ]);
        }

        $clientChallenge = (string) ($clientData['challenge'] ?? '');

        if (! hash_equals($pendingChallenge, $clientChallenge)) {
            throw ValidationException::withMessages([
                'response.clientDataJSON' => ['Authentication challenge is invalid or expired.'],
            ]);
        }

        if (! hash_equals((string) ($clientData['origin'] ?? ''), $pendingOrigin)) {
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

        $signatureRaw = $this->base64UrlService->decode((string) data_get($payload, 'response.signature'));

        if ($signatureRaw === '') {
            throw ValidationException::withMessages([
                'response.signature' => ['WebAuthn signature is invalid.'],
            ]);
        }

        try {
            $parsedAuthenticatorData = $this->webauthnVerificationService->parseAuthenticatorData($authenticatorDataRaw);
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

        if (
            ! is_string($credential->public_key) ||
            $credential->public_key === '' ||
            ! is_numeric($credential->public_key_algorithm)
        ) {
            return response()->json([
                'message' => 'The registered security key is incomplete and cannot be verified.',
            ], 401);
        }

        $isValidSignature = $this->webauthnVerificationService->verifyAssertionSignature(
            $credential->public_key,
            (int) $credential->public_key_algorithm,
            $authenticatorDataRaw,
            $clientDataRaw,
            $signatureRaw,
        );

        if (! $isValidSignature) {
            throw ValidationException::withMessages([
                'response.signature' => ['WebAuthn signature verification failed.'],
            ]);
        }

        $newSignCount = (int) $parsedAuthenticatorData['signCount'];
        $storedSignCount = (int) $credential->sign_count;

        if (($storedSignCount > 0 || $newSignCount > 0) && $newSignCount <= $storedSignCount) {
            return response()->json([
                'message' => 'WebAuthn sign counter check failed.',
            ], 401);
        }

        /** @var User|null $user */
        $user = $credential->user()->first();

        if ($user === null) {
            return response()->json([
                'message' => 'The security key owner account is no longer available.',
            ], 401);
        }

        if ($user->isSuspended()) {
            $request->session()->forget([
                self::LOGIN_CHALLENGE_KEY,
                self::LOGIN_ORIGIN_KEY,
                self::LOGIN_RP_ID_KEY,
                self::LOGIN_USER_ID_KEY,
                self::PENDING_TOTP_USER_KEY,
            ]);

            $this->auditEventService->failure(
                $request,
                AuditEventType::AuthLoginFailed,
                $user,
                metadata: [
                    'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                    'credentialName' => $credential->name,
                    'method' => 'passkey',
                    'reason' => 'account_suspended',
                ],
            );

            return response()->json([
                'message' => 'This account is suspended.',
                'error' => [
                    'code' => 'account_suspended',
                ],
            ], 403);
        }

        $request->session()->forget(self::PENDING_TOTP_USER_KEY);
        Auth::guard('web')->login($user);
        $credential->forceFill([
            'sign_count' => $newSignCount,
            'last_used_at' => now(),
        ])->save();
        $user->forceFill([
            'last_sign_in_at' => now(),
        ])->save();

        $request->session()->forget([
            self::LOGIN_CHALLENGE_KEY,
            self::LOGIN_ORIGIN_KEY,
            self::LOGIN_RP_ID_KEY,
            self::LOGIN_USER_ID_KEY,
        ]);

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $deviceContext = $this->browserDeviceService->rememberAuthenticatedDevice($request, $user);

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthPasskeyLoginSucceeded,
            $user,
            metadata: [
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'credentialName' => $credential->name,
                'method' => 'passkey',
                'signCount' => $newSignCount,
                ...$deviceContext['metadata'],
            ],
        );

        if ($deviceContext['isNewDevice']) {
            $this->auditEventService->success(
                $request,
                AuditEventType::AuthDeviceRegistered,
                $user,
                metadata: [
                    'method' => 'passkey',
                    ...$deviceContext['metadata'],
                ],
            );
        }

        return response()->json([
            'message' => 'Login successful.',
            'requiresTwoFactor' => false,
            'user' => $this->authenticatedUserViewService->toArray($user->fresh() ?? $user),
        ]);
    }
}
