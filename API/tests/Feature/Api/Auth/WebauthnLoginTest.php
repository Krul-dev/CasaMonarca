<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Auth\SessionCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebauthnLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_webauthn_login_options_returns_challenge_for_registered_key(): void
    {
        $user = User::factory()->create();
        $credentialId = app(Base64UrlService::class)->encode(random_bytes(16));

        $user->webauthnCredentials()->create([
            'credential_id' => $credentialId,
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Hardware key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        $csrfResponse = $this->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/webauthn/login/options', [
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'WebAuthn login challenge created.',
                'options' => [
                    'rpId' => 'localhost',
                ],
            ])
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rpId',
                    'allowCredentials',
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthPasskeyLoginChallengeStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_webauthn_login_options_returns_validation_error_when_no_keys_are_registered(): void
    {
        $csrfResponse = $this->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/webauthn/login/options', [
                'email' => 'missing@casamonarca.local',
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'No security keys were found for this account.',
            ]);
    }

    public function test_webauthn_login_verify_authenticates_user_for_valid_challenge(): void
    {
        $base64UrlService = app(Base64UrlService::class);
        $user = User::factory()->create();
        $credentialId = $base64UrlService->encode(random_bytes(16));
        $keyPair = $this->generateRsaCredentialKeyPair();

        $user->webauthnCredentials()->create([
            'credential_id' => $credentialId,
            'public_key' => $base64UrlService->encode($keyPair['publicKeyDer']),
            'public_key_algorithm' => -257,
            'name' => 'Hardware key',
            'transports' => ['usb'],
            'attestation_object' => $base64UrlService->encode(random_bytes(32)),
            'client_data_json' => $base64UrlService->encode(random_bytes(32)),
            'sign_count' => 1,
        ]);
        $capabilities = app(SessionCapabilityService::class)->forUser($user);

        $optionsCsrfResponse = $this->getJson('/csrf-token');

        $optionsResponse = $this->withHeader('X-CSRF-TOKEN', (string) $optionsCsrfResponse->json('csrfToken'))
            ->postJson('/webauthn/login/options', [
                'email' => $user->email,
            ]);

        $challenge = (string) $optionsResponse->json('options.challenge');
        $verifyCsrfResponse = $this->getJson('/csrf-token');
        $clientDataRaw = json_encode([
            'type' => 'webauthn.get',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ], JSON_THROW_ON_ERROR);
        $clientData = $base64UrlService->encode($clientDataRaw);
        $authenticatorDataRaw = hash('sha256', 'localhost', true)
            .chr(0x01)
            .pack('N', 2);

        $signaturePayload = $authenticatorDataRaw.hash('sha256', $clientDataRaw, true);
        $signatureRaw = '';
        openssl_sign($signaturePayload, $signatureRaw, $keyPair['privateKeyPem'], OPENSSL_ALGO_SHA256);

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
            'X-CSRF-TOKEN' => (string) $verifyCsrfResponse->json('csrfToken'),
        ])
            ->postJson('/webauthn/login/verify', [
                'id' => $credentialId,
                'rawId' => $credentialId,
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => $clientData,
                    'authenticatorData' => $base64UrlService->encode($authenticatorDataRaw),
                    'signature' => $base64UrlService->encode($signatureRaw),
                ],
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Login successful.',
                'requiresTwoFactor' => false,
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'role' => $user->role->value,
                    'capabilities' => $capabilities,
                ],
            ]);

        $response->assertCookie(BrowserDeviceService::COOKIE_NAME);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()?->last_sign_in_at);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'alias' => 'Safari on macOS',
        ]);
        $this->assertDatabaseHas('webauthn_credentials', [
            'credential_id' => $credentialId,
            'sign_count' => 2,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthPasskeyLoginSucceeded->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthDeviceRegistered->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_webauthn_login_verify_requires_started_challenge(): void
    {
        $base64UrlService = app(Base64UrlService::class);

        $csrfResponse = $this->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/webauthn/login/verify', [
                'id' => $base64UrlService->encode(random_bytes(16)),
                'rawId' => $base64UrlService->encode(random_bytes(16)),
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => $base64UrlService->encode(random_bytes(20)),
                    'authenticatorData' => $base64UrlService->encode(random_bytes(37)),
                    'signature' => $base64UrlService->encode(random_bytes(64)),
                ],
            ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'WebAuthn login challenge was not initiated.',
            ]);
    }

    /**
     * @return array{privateKeyPem: string, publicKeyDer: string}
     */
    private function generateRsaCredentialKeyPair(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            $this->fail('Could not generate RSA private key for WebAuthn test.');
        }

        $privateKeyPem = '';
        openssl_pkey_export($privateKey, $privateKeyPem);

        $details = openssl_pkey_get_details($privateKey);

        if (! is_array($details) || ! isset($details['key']) || ! is_string($details['key'])) {
            $this->fail('Could not extract public key details for WebAuthn test.');
        }

        return [
            'privateKeyPem' => $privateKeyPem,
            'publicKeyDer' => $this->pemToDer($details['key']),
        ];
    }

    private function pemToDer(string $pem): string
    {
        $normalizedPem = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pem);

        if (! is_string($normalizedPem)) {
            $this->fail('Could not normalize public key PEM content.');
        }

        $der = base64_decode($normalizedPem, true);

        if ($der === false) {
            $this->fail('Could not decode public key PEM to DER.');
        }

        return $der;
    }
}
