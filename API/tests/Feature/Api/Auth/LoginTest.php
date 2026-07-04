<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Auth\SessionCapabilityService;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_csrf_token_endpoint_returns_a_token_payload(): void
    {
        $response = $this->getJson('/csrf-token');

        $response
            ->assertOk()
            ->assertJsonStructure([
                'csrfToken',
            ]);
    }

    public function test_login_authenticates_a_valid_user_and_returns_user_payload(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);
        $capabilities = app(SessionCapabilityService::class)->forUser($user);

        $csrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0',
            'X-CSRF-TOKEN' => (string) $csrfResponse->json('csrfToken'),
        ])
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Login successful.',
                'requiresTwoFactor' => false,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'capabilities' => $capabilities,
                ],
            ]);

        $response->assertCookie(BrowserDeviceService::COOKIE_NAME);
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()?->last_sign_in_at);
        $this->assertSame(1, $user->browserDevices()->count());
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'alias' => 'Firefox on Linux',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthLoginSucceeded->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthDeviceRegistered->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_login_requires_totp_for_users_with_two_factor_enabled(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $csrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ]);

        $response
            ->assertAccepted()
            ->assertJson([
                'message' => 'Two-factor authentication code is required.',
                'requiresTwoFactor' => true,
            ]);

        $this->assertGuest();
        $response->assertSessionHas('auth.pending_totp_user_id', $user->id);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthTotpChallengeStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_totp_login_authenticates_user_with_valid_code(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        $capabilities = app(SessionCapabilityService::class)->forUser($user);

        $csrfResponse = $this->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ])
            ->assertAccepted();

        $totpCode = app(TotpService::class)->currentCode((string) $user->two_factor_secret);
        $totpCsrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36',
            'X-CSRF-TOKEN' => (string) $totpCsrfResponse->json('csrfToken'),
        ])
            ->postJson('/login/totp', [
                'code' => $totpCode,
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Login successful.',
                'requiresTwoFactor' => false,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'capabilities' => $capabilities,
                ],
            ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()?->last_sign_in_at);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'alias' => 'Chrome on Windows',
        ]);
        $response->assertSessionMissing('auth.pending_totp_user_id');
    }

    public function test_totp_login_rejects_invalid_or_expired_code(): void
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

        $totpCsrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeader('X-CSRF-TOKEN', (string) $totpCsrfResponse->json('csrfToken'))
            ->postJson('/login/totp', [
                'code' => '000000',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'The authentication code is invalid or expired.',
            ])
            ->assertJsonValidationErrors([
                'code',
            ]);

        $this->assertGuest();
    }

    public function test_totp_login_returns_unauthorized_when_challenge_was_not_started(): void
    {
        $csrfResponse = $this->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login/totp', [
                'code' => '123456',
            ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Two-factor challenge was not initiated.',
            ]);
    }

    public function test_login_returns_validation_errors_for_missing_fields(): void
    {
        $csrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'password',
            ]);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-password',
        ]);

        $csrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        $response
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'These credentials do not match our records.',
            ])
            ->assertJsonValidationErrors([
                'email',
            ]);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthLoginFailed->value,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
    }
}
