<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequireSecurityEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_with_passkey_but_missing_totp_is_blocked_from_protected_modules(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $this->createPasskey($admin, 'admin-key');

        $this->actingAs($admin)
            ->getJson('/documents')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Security enrollment is required before accessing this module.',
                'error' => [
                    'code' => 'security_enrollment_required',
                    'requires' => [
                        'totp' => true,
                        'passkey' => true,
                    ],
                    'enrolled' => [
                        'totp' => false,
                        'passkey' => true,
                    ],
                    'missing' => [
                        'totp' => true,
                        'passkey' => false,
                    ],
                ],
            ]);
    }

    public function test_coordinator_without_totp_and_passkey_is_blocked_from_protected_modules(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $this->actingAs($coordinator)
            ->getJson('/documents')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Security enrollment is required before accessing this module.',
                'error' => [
                    'code' => 'security_enrollment_required',
                    'requires' => [
                        'totp' => true,
                        'passkey' => true,
                    ],
                    'enrolled' => [
                        'totp' => false,
                        'passkey' => false,
                    ],
                    'missing' => [
                        'totp' => true,
                        'passkey' => true,
                    ],
                ],
            ]);
    }

    public function test_non_coordinator_without_totp_is_blocked_from_protected_modules(): void
    {
        $nonCoordinator = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $this->actingAs($nonCoordinator)
            ->getJson('/documents')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Security enrollment is required before accessing this module.',
                'error' => [
                    'code' => 'security_enrollment_required',
                    'requires' => [
                        'totp' => true,
                        'passkey' => false,
                    ],
                    'enrolled' => [
                        'totp' => false,
                        'passkey' => false,
                    ],
                    'missing' => [
                        'totp' => true,
                        'passkey' => false,
                    ],
                ],
            ]);
    }

    public function test_coordinator_with_totp_and_passkey_can_access_protected_modules(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $this->createPasskey($coordinator, 'coordinator-key');

        $this->actingAs($coordinator)
            ->getJson('/documents')
            ->assertOk()
            ->assertJson([
                'message' => 'Documents loaded successfully.',
            ]);
    }

    public function test_admin_with_totp_and_passkey_can_access_protected_modules(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $this->createPasskey($admin, 'admin-key');

        $this->actingAs($admin)
            ->getJson('/documents')
            ->assertOk()
            ->assertJson([
                'message' => 'Documents loaded successfully.',
            ]);
    }

    public function test_non_coordinator_with_totp_can_access_protected_modules_without_passkey(): void
    {
        $nonCoordinator = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $this->actingAs($nonCoordinator)
            ->getJson('/documents')
            ->assertOk()
            ->assertJson([
                'message' => 'Documents loaded successfully.',
            ]);
    }

    public function test_coordinator_without_full_enrollment_can_still_access_totp_and_passkey_setup_endpoints(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $this->actingAs($coordinator)
            ->postJson('/totp/enroll/options')
            ->assertOk()
            ->assertJson([
                'message' => 'TOTP enrollment challenge created.',
            ]);

        $this->actingAs($coordinator)
            ->postJson('/webauthn/register/options')
            ->assertOk()
            ->assertJson([
                'message' => 'WebAuthn registration challenge created.',
            ]);
    }

    private function createPasskey(User $user, string $name): void
    {
        $user->webauthnCredentials()->create([
            'credential_id' => app(Base64UrlService::class)->encode(random_bytes(16)),
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => $name,
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);
    }
}
