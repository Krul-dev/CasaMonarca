<?php

namespace App\Services\Auth;

use InvalidArgumentException;

class WebauthnVerificationService
{
    private const FLAG_USER_PRESENT = 0x01;

    private const FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED = 0x40;

    public function __construct(private readonly Base64UrlService $base64UrlService) {}

    /**
     * @return array{
     *     rpIdHash: string,
     *     flags: int,
     *     signCount: int,
     *     attestedCredentialId: string|null,
     * }
     */
    public function parseAuthenticatorData(
        string $authenticatorDataRaw,
        bool $requireAttestedCredentialData = false,
    ): array {
        if (strlen($authenticatorDataRaw) < 37) {
            throw new InvalidArgumentException('Authenticator data is too short.');
        }

        $flags = ord($authenticatorDataRaw[32]);
        $signCountUnpacked = unpack('NsignCount', substr($authenticatorDataRaw, 33, 4));

        if (! is_array($signCountUnpacked) || ! isset($signCountUnpacked['signCount'])) {
            throw new InvalidArgumentException('Authenticator sign counter is invalid.');
        }

        $attestedCredentialId = null;

        if (($flags & self::FLAG_ATTESTED_CREDENTIAL_DATA_INCLUDED) !== 0) {
            $offset = 37;
            $requiredLength = $offset + 16 + 2;

            if (strlen($authenticatorDataRaw) < $requiredLength) {
                throw new InvalidArgumentException('Attested credential data is incomplete.');
            }

            $offset += 16;
            $credentialLengthUnpacked = unpack('ncredentialLength', substr($authenticatorDataRaw, $offset, 2));

            if (! is_array($credentialLengthUnpacked) || ! isset($credentialLengthUnpacked['credentialLength'])) {
                throw new InvalidArgumentException('Attested credential ID length is invalid.');
            }

            $offset += 2;
            $credentialLength = (int) $credentialLengthUnpacked['credentialLength'];
            $endOffset = $offset + $credentialLength;

            if (strlen($authenticatorDataRaw) < $endOffset) {
                throw new InvalidArgumentException('Attested credential ID is incomplete.');
            }

            $attestedCredentialId = substr($authenticatorDataRaw, $offset, $credentialLength);
        }

        if ($requireAttestedCredentialData && ! is_string($attestedCredentialId)) {
            throw new InvalidArgumentException('Attested credential data is required.');
        }

        return [
            'rpIdHash' => substr($authenticatorDataRaw, 0, 32),
            'flags' => $flags,
            'signCount' => (int) $signCountUnpacked['signCount'],
            'attestedCredentialId' => $attestedCredentialId,
        ];
    }

    public function isUserPresent(int $flags): bool
    {
        return ($flags & self::FLAG_USER_PRESENT) !== 0;
    }

    public function supportsPublicKeyAlgorithm(int $algorithm): bool
    {
        return in_array($algorithm, [-7, -257], true);
    }

    public function hashRpId(string $rpId): string
    {
        return hash('sha256', $rpId, true);
    }

    public function verifyAssertionSignature(
        string $publicKeyBase64Url,
        int $publicKeyAlgorithm,
        string $authenticatorDataRaw,
        string $clientDataRaw,
        string $signatureRaw,
    ): bool {
        if (! $this->supportsPublicKeyAlgorithm($publicKeyAlgorithm)) {
            return false;
        }

        $publicKeyDer = $this->base64UrlService->decode($publicKeyBase64Url);

        if ($publicKeyDer === '') {
            return false;
        }

        $verificationData = $authenticatorDataRaw.hash('sha256', $clientDataRaw, true);
        $verificationResult = openssl_verify(
            $verificationData,
            $signatureRaw,
            $this->toPemPublicKey($publicKeyDer),
            $this->resolveOpenSslAlgorithm($publicKeyAlgorithm),
        );

        return $verificationResult === 1;
    }

    private function toPemPublicKey(string $publicKeyDer): string
    {
        $pemBody = chunk_split(base64_encode($publicKeyDer), 64, "\n");

        return "-----BEGIN PUBLIC KEY-----\n{$pemBody}-----END PUBLIC KEY-----\n";
    }

    private function resolveOpenSslAlgorithm(int $publicKeyAlgorithm): int
    {
        return match ($publicKeyAlgorithm) {
            -7, -257 => OPENSSL_ALGO_SHA256,
            default => throw new InvalidArgumentException('Unsupported public key algorithm.'),
        };
    }
}
