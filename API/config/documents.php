<?php

$decodePem = static function (?string $encoded): ?string {
    if (! is_string($encoded) || $encoded === '') {
        return null;
    }

    $decoded = base64_decode($encoded, true);

    return is_string($decoded) && $decoded !== '' ? $decoded : null;
};

return [
    'signature_validity_days' => max(1, (int) env('DOCUMENT_SIGNATURE_VALIDITY_DAYS', 365)),
    'package_signing' => [
        'key_id' => env('VERIFICATION_PACKAGE_SIGNING_KEY_ID', 'casa-monarca-dev'),
        'private_key' => $decodePem(env('VERIFICATION_PACKAGE_SIGNING_PRIVATE_KEY_BASE64')),
        'public_key' => $decodePem(env('VERIFICATION_PACKAGE_SIGNING_PUBLIC_KEY_BASE64')),
    ],
];
