<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebauthnRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webauthn_registration_endpoints_require_authentication(): void
    {
        $this->postJson('/webauthn/register/options')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->postJson('/webauthn/register/verify', [
            'id' => 'test-credential-id',
            'rawId' => 'test-credential-id',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => 'test',
                'attestationObject' => 'test',
            ],
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->getJson('/webauthn/credentials')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);

        $this->deleteJson('/webauthn/credentials/test-credential-id')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_authenticated_user_can_register_security_key(): void
    {
        $user = User::factory()->create();

        $csrfResponse = $this->actingAs($user)->getJson('/csrf-token');

        $optionsResponse = $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/webauthn/register/options');

        $optionsResponse
            ->assertOk()
            ->assertJson([
                'message' => 'WebAuthn registration challenge created.',
            ])
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rp',
                    'user',
                    'pubKeyCredParams',
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthPasskeyRegistrationChallengeStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);

        $challenge = (string) $optionsResponse->json('options.challenge');
        $base64Url = app(Base64UrlService::class);
        $clientDataJson = $base64Url->encode(json_encode([
            'type' => 'webauthn.create',
            'challenge' => $challenge,
            'origin' => 'http://localhost',
        ], JSON_THROW_ON_ERROR));
        $attestationObject = $base64Url->encode(random_bytes(32));

        $verifyCsrfResponse = $this->actingAs($user)->getJson('/csrf-token');

        $rawCredentialId = random_bytes(16);
        $authenticatorData = hash('sha256', 'localhost', true)
            .chr(0x41)
            .pack('N', 0)
            .random_bytes(16)
            .pack('n', strlen($rawCredentialId))
            .$rawCredentialId
            .random_bytes(16);
        $publicKeyDer = random_bytes(48);

        $verifyResponse = $this->withHeader('X-CSRF-TOKEN', (string) $verifyCsrfResponse->json('csrfToken'))
            ->postJson('/webauthn/register/verify', [
                'id' => $base64Url->encode($rawCredentialId),
                'rawId' => $base64Url->encode($rawCredentialId),
                'type' => 'public-key',
                'response' => [
                    'clientDataJSON' => $clientDataJson,
                    'attestationObject' => $attestationObject,
                    'authenticatorData' => $base64Url->encode($authenticatorData),
                    'publicKey' => $base64Url->encode($publicKeyDer),
                    'publicKeyAlgorithm' => -7,
                ],
                'transports' => ['usb'],
                'name' => 'YubiKey 5',
            ]);

        $verifyResponse
            ->assertOk()
            ->assertJson([
                'message' => 'Security key registered successfully.',
                'credential' => [
                    'id' => $base64Url->encode($rawCredentialId),
                    'name' => 'YubiKey 5',
                ],
            ]);

        $this->assertDatabaseHas('webauthn_credentials', [
            'user_id' => $user->id,
            'credential_id' => $base64Url->encode($rawCredentialId),
            'public_key_algorithm' => -7,
            'name' => 'YubiKey 5',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthPasskeyRegistered->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);

        $this->actingAs($user)
            ->getJson('/webauthn/credentials')
            ->assertOk()
            ->assertJson([
                'message' => 'Registered security keys loaded.',
            ])
            ->assertJsonCount(1, 'credentials');
    }

    public function test_authenticated_user_can_remove_registered_security_key(): void
    {
        $user = User::factory()->create();
        $base64Url = app(Base64UrlService::class);
        $credentialId = $base64Url->encode(random_bytes(16));

        $user->webauthnCredentials()->create([
            'credential_id' => $credentialId,
            'public_key' => $base64Url->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'YubiKey 5',
            'transports' => ['usb'],
            'attestation_object' => $base64Url->encode(random_bytes(32)),
            'client_data_json' => $base64Url->encode(random_bytes(32)),
        ]);

        $csrfResponse = $this->actingAs($user)->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->deleteJson('/webauthn/credentials/'.$credentialId)
            ->assertOk()
            ->assertJson([
                'message' => 'Security key removed successfully.',
            ]);

        $this->assertDatabaseMissing('webauthn_credentials', [
            'credential_id' => $credentialId,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthPasskeyRemoved->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_user_cannot_remove_another_users_security_key(): void
    {
        $currentUser = User::factory()->create();
        $anotherUser = User::factory()->create();
        $base64Url = app(Base64UrlService::class);
        $credentialId = $base64Url->encode(random_bytes(16));

        $anotherUser->webauthnCredentials()->create([
            'credential_id' => $credentialId,
            'public_key' => $base64Url->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Other user key',
            'transports' => ['usb'],
            'attestation_object' => $base64Url->encode(random_bytes(32)),
            'client_data_json' => $base64Url->encode(random_bytes(32)),
        ]);

        $csrfResponse = $this->actingAs($currentUser)->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->deleteJson('/webauthn/credentials/'.$credentialId)
            ->assertNotFound()
            ->assertJson([
                'message' => 'Security key not found.',
            ]);
    }
}
