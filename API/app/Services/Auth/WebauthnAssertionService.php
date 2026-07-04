<?php

namespace App\Services\Auth;

use App\Models\WebauthnCredential;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WebauthnAssertionService
{
    public function __construct(
        private readonly Base64UrlService $base64UrlService,
        private readonly WebauthnVerificationService $webauthnVerificationService,
    ) {}

    public function resolveRequestOrigin(Request $request): string
    {
        $headerOrigin = (string) $request->headers->get('origin', '');

        return $headerOrigin !== ''
            ? $headerOrigin
            : $request->getSchemeAndHttpHost();
    }

    public function resolveOriginHost(string $origin): ?string
    {
        $originHost = parse_url($origin, PHP_URL_HOST);

        return is_string($originHost) && $originHost !== ''
            ? $originHost
            : null;
    }

    public function isIpHost(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException
     */
    public function verifyAssertionPayload(
        array $payload,
        WebauthnCredential $credential,
        string $pendingChallenge,
        string $pendingOrigin,
        string $pendingRpId,
    ): int {
        if (! hash_equals((string) $payload['id'], (string) $payload['rawId'])) {
            throw ValidationException::withMessages([
                'rawId' => ['Credential ID is inconsistent with raw credential ID.'],
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
            throw ValidationException::withMessages([
                'id' => ['The selected security key is incomplete and cannot be verified.'],
            ]);
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
            throw ValidationException::withMessages([
                'response.signature' => ['WebAuthn sign counter check failed.'],
            ]);
        }

        return $newSignCount;
    }
}
