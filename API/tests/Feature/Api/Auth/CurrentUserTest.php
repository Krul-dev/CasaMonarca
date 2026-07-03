<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\SessionCapabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurrentUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_user_returns_unauthorized_for_guests(): void
    {
        $this->getJson('/me')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_current_user_returns_json_unauthorized_without_json_accept_header(): void
    {
        $this->get('/me')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_current_user_returns_authenticated_user_payload(): void
    {
        $user = User::factory()->create();
        $capabilities = app(SessionCapabilityService::class)->forUser($user);

        $this->actingAs($user)
            ->getJson('/me')
            ->assertOk()
            ->assertJson([
                'message' => 'Session authenticated.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'capabilities' => $capabilities,
                ],
            ]);
    }

    public function test_admin_missing_totp_gets_blocked_admin_modules_in_session_capabilities(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $admin->webauthnCredentials()->create([
            'credential_id' => app(Base64UrlService::class)->encode(random_bytes(16)),
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Admin key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        $this->actingAs($admin)
            ->getJson('/me')
            ->assertOk()
            ->assertJsonPath('user.capabilities.security.requires.totp', true)
            ->assertJsonPath('user.capabilities.security.requires.passkey', true)
            ->assertJsonPath('user.capabilities.security.enrolled.passkey', true)
            ->assertJsonPath('user.capabilities.security.missing.totp', true)
            ->assertJsonPath('user.capabilities.security.isFullyEnrolled', false)
            ->assertJsonPath('user.capabilities.modules.dashboard', true)
            ->assertJsonPath('user.capabilities.modules.admin', false)
            ->assertJsonPath('user.capabilities.modules.logging', false);
    }

    public function test_current_user_stays_unauthenticated_while_totp_challenge_is_pending(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $csrfResponse = $this->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ])
            ->assertAccepted();

        $this->getJson('/me')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }
}
