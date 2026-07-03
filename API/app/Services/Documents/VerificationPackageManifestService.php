<?php

namespace App\Services\Documents;

use RuntimeException;

class VerificationPackageManifestService
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return array{manifest: array<string, mixed>, signature: array<string, mixed>}
     */
    public function sign(array $manifest): array
    {
        $canonicalManifest = $this->canonicalJson($manifest);

        return [
            'manifest' => $manifest,
            'signature' => $this->signPayload($canonicalManifest, 'manifest', [
                'manifestSha256' => hash('sha256', $canonicalManifest),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function signPayload(string $payload, string $purpose, array $metadata = []): array
    {
        $signature = [
            'status' => 'unsigned',
            'algorithm' => 'RSASSA-PKCS1-v1_5-SHA256',
            'keyId' => config('documents.package_signing.key_id'),
            'purpose' => $purpose,
            'payloadSha256' => hash('sha256', $payload),
            'reason' => 'package_signing_key_not_configured',
            ...$metadata,
        ];

        $privateKeyPem = config('documents.package_signing.private_key');
        $publicKeyPem = config('documents.package_signing.public_key');

        if (! is_string($privateKeyPem) || $privateKeyPem === '' || ! is_string($publicKeyPem) || $publicKeyPem === '') {
            return $signature;
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new RuntimeException('Verification package signing private key is invalid.');
        }

        $signed = openssl_sign($payload, $signatureBytes, $privateKey, OPENSSL_ALGO_SHA256);

        if ($signed !== true) {
            throw new RuntimeException('Verification package payload could not be signed.');
        }

        return [
            'status' => 'signed',
            'algorithm' => 'RSASSA-PKCS1-v1_5-SHA256',
            'keyId' => config('documents.package_signing.key_id'),
            'purpose' => $purpose,
            'payloadSha256' => hash('sha256', $payload),
            'publicKeyPem' => $publicKeyPem,
            'publicKeySha256' => hash('sha256', $publicKeyPem),
            'value' => $this->base64UrlEncode($signatureBytes),
            ...$metadata,
        ];
    }

    public function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map($this->canonicalJson(...), $value)).']';
            }

            ksort($value);

            return '{'.implode(',', array_map(
                fn (string|int $key): string => json_encode((string) $key, JSON_THROW_ON_ERROR).':'.$this->canonicalJson($value[$key]),
                array_keys($value),
            )).'}';
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
