<?php

namespace App\Services\Documents;

use RuntimeException;

class VerificationPackageSigningKeyService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $publicKeyPem = config('documents.package_signing.public_key');
        $privateKeyPem = config('documents.package_signing.private_key');
        $publicKeyFingerprint = is_string($publicKeyPem) && $publicKeyPem !== ''
            ? hash('sha256', $publicKeyPem)
            : null;
        $privateKeyConfigured = is_string($privateKeyPem) && $privateKeyPem !== '';
        $publicKeyConfigured = is_string($publicKeyPem) && $publicKeyPem !== '';
        $envPath = base_path('.env');

        return [
            'algorithm' => 'RSASSA-PKCS1-v1_5-SHA256',
            'configured' => $privateKeyConfigured && $publicKeyConfigured,
            'configCached' => app()->configurationIsCached(),
            'envWritable' => file_exists($envPath) && is_writable($envPath),
            'keyId' => config('documents.package_signing.key_id'),
            'privateKeyConfigured' => $privateKeyConfigured,
            'publicKeyConfigured' => $publicKeyConfigured,
            'publicKeyFingerprint' => $publicKeyFingerprint,
            'rotationSupported' => file_exists($envPath) && is_writable($envPath),
        ];
    }

    /**
     * @return array{
     *     keyId: string,
     *     privateKeyPem: string,
     *     publicKeyPem: string,
     *     privateKeyBase64: string,
     *     publicKeyBase64: string,
     *     publicKeyFingerprint: string,
     *     bits: int
     * }
     */
    public function generate(string $keyId, int $bits = 3072): array
    {
        $bits = max(2048, $bits);
        $key = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($key === false || ! openssl_pkey_export($key, $privateKeyPem)) {
            throw new RuntimeException('Could not generate the package signing private key.');
        }

        $details = openssl_pkey_get_details($key);
        $publicKeyPem = is_array($details) ? ($details['key'] ?? null) : null;

        if (! is_string($publicKeyPem) || $publicKeyPem === '') {
            throw new RuntimeException('Could not export the package signing public key.');
        }

        return [
            'keyId' => $keyId,
            'privateKeyPem' => $privateKeyPem,
            'publicKeyPem' => $publicKeyPem,
            'privateKeyBase64' => base64_encode($privateKeyPem),
            'publicKeyBase64' => base64_encode($publicKeyPem),
            'publicKeyFingerprint' => hash('sha256', $publicKeyPem),
            'bits' => $bits,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rotate(string $keyId, int $bits = 3072): array
    {
        $previousSummary = $this->summary();
        $generatedKey = $this->generate($keyId, $bits);
        $this->writeEnvValues([
            'VERIFICATION_PACKAGE_SIGNING_KEY_ID' => $generatedKey['keyId'],
            'VERIFICATION_PACKAGE_SIGNING_PRIVATE_KEY_BASE64' => $generatedKey['privateKeyBase64'],
            'VERIFICATION_PACKAGE_SIGNING_PUBLIC_KEY_BASE64' => $generatedKey['publicKeyBase64'],
        ], true);

        config()->set('documents.package_signing.key_id', $generatedKey['keyId']);
        config()->set('documents.package_signing.private_key', $generatedKey['privateKeyPem']);
        config()->set('documents.package_signing.public_key', $generatedKey['publicKeyPem']);

        return [
            'previous' => $previousSummary,
            'current' => $this->summary(),
            'bits' => $generatedKey['bits'],
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    public function writeEnvValues(array $values, bool $allowOverwrite = false): void
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            throw new RuntimeException('.env does not exist.');
        }

        if (! is_writable($envPath)) {
            throw new RuntimeException('.env is not writable by the current process.');
        }

        $env = file_get_contents($envPath);

        if (! is_string($env)) {
            throw new RuntimeException('Could not read .env.');
        }

        foreach ($values as $name => $value) {
            $pattern = '/^'.preg_quote($name, '/').'=.*$/m';
            $hasExistingValue = (bool) preg_match('/^'.preg_quote($name, '/').'=.+$/m', $env);

            if ($hasExistingValue && ! $allowOverwrite) {
                throw new RuntimeException("{$name} already exists in .env.");
            }

            if (preg_match($pattern, $env)) {
                $env = (string) preg_replace($pattern, $name.'='.$value, $env);
            } else {
                $env = rtrim($env).PHP_EOL.$name.'='.$value.PHP_EOL;
            }
        }

        file_put_contents($envPath, $env);
    }
}
