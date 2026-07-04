<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AuditEvent;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Documents\VerificationPackageSigningKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserDirectoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_user_directory_with_enrollment_state(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@casamonarca.local',
            'role' => UserRole::Admin->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);
        $coordinator = User::factory()->create([
            'email' => 'coordinator@casamonarca.local',
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        $volunteer = User::factory()->create([
            'email' => 'volunteer@casamonarca.local',
            'role' => UserRole::Volunteer->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'last_sign_in_at' => now()->subMinutes(5),
        ]);
        $volunteer->browserDevices()->create([
            'device_identifier_hash' => str_repeat('a', 64),
            'alias' => 'Firefox on Linux',
            'user_agent' => 'Mozilla/5.0 Firefox/140.0',
            'last_ip_address' => '127.0.0.1',
            'first_seen_at' => now()->subDays(2),
            'last_seen_at' => now()->subMinutes(5),
            'last_login_at' => now()->subMinutes(5),
        ]);

        $coordinator->webauthnCredentials()->create([
            'credential_id' => app(Base64UrlService::class)->encode(random_bytes(16)),
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Coordinator key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/admin/users')
            ->assertOk()
            ->assertJson([
                'message' => 'Users loaded successfully.',
            ])
            ->assertJsonPath('users.0.email', $volunteer->email)
            ->assertJsonPath('users.0.status', 'active')
            ->assertJsonPath('users.0.enrollment.requires.totp', true)
            ->assertJsonPath('users.0.enrollment.missing.totp', true)
            ->assertJsonPath('users.0.devices.count', 1)
            ->assertJsonPath('users.0.devices.recent.0.deviceId', str_repeat('a', 16))
            ->assertJsonPath('users.0.devices.recent.0.alias', 'Firefox on Linux')
            ->assertJsonPath('users.1.email', $coordinator->email)
            ->assertJsonPath('users.1.enrollment.requires.passkey', true)
            ->assertJsonPath('users.1.enrollment.enrolled.passkey', true)
            ->assertJsonPath('users.1.enrollment.passkeyCount', 1);

        $this->assertNotNull($response->json('users.0.lastSignInAt'));

        $adminSummary = collect($response->json('users'))
            ->firstWhere('email', $admin->email);

        $this->assertIsArray($adminSummary);
        $this->assertTrue($adminSummary['enrollment']['requires']['totp']);
        $this->assertTrue($adminSummary['enrollment']['requires']['passkey']);
        $this->assertTrue($adminSummary['enrollment']['missing']['totp']);
        $this->assertTrue($adminSummary['enrollment']['missing']['passkey']);
        $this->assertFalse($adminSummary['enrollment']['isFullyEnrolled']);
    }

    public function test_non_admin_cannot_list_user_directory(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
        ]);

        $this->actingAs($coordinator)
            ->getJson('/admin/users')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden_role');
    }

    public function test_guest_cannot_list_user_directory(): void
    {
        $this->getJson('/admin/users')
            ->assertUnauthorized();
    }

    public function test_admin_can_assign_operational_role(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        $this->createPasskey($target, 'credential-target');

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::Coordinator->value,
                'reason' => 'Coordinator duties assigned',
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Role assignment authentication challenge created.',
                'assignment' => [
                    'targetUserId' => $target->id,
                    'previousRole' => UserRole::NonCoordinator->value,
                    'targetRole' => UserRole::Coordinator->value,
                ],
            ]);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(10);
        });

        $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/role/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'User role updated successfully.',
                'user' => [
                    'id' => $target->id,
                    'role' => UserRole::Coordinator->value,
                    'enrollment' => [
                        'requires' => [
                            'totp' => true,
                            'passkey' => true,
                        ],
                        'missing' => [
                            'totp' => false,
                            'passkey' => false,
                        ],
                    ],
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => UserRole::Coordinator->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserRoleChangeChallengeStarted->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserRoleChanged->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);

        $roleChangeEvent = AuditEvent::query()
            ->where('event_type', AuditEventType::AdminUserRoleChanged->value)
            ->where('resource_id', $target->id)
            ->sole();

        $this->assertSame($target->name, $roleChangeEvent->metadata['targetUserName']);
        $this->assertSame($target->email, $roleChangeEvent->metadata['targetUserEmail']);
        $this->assertSame('Coordinator duties assigned', $roleChangeEvent->metadata['reason']);
    }

    public function test_admin_cannot_promote_user_to_coordinator_until_target_has_passkey(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::Coordinator->value,
                'reason' => 'Needs coordinator access',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'coordinator_passkey_required');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => UserRole::NonCoordinator->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserRoleChanged->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Denied->value,
        ]);
    }

    public function test_coordinator_promotion_rechecks_target_passkey_after_challenge_creation(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        $targetPasskey = $target->webauthnCredentials()->create([
            'credential_id' => 'credential-target',
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Target key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::Coordinator->value,
            ])
            ->assertOk();

        $targetPasskey->delete();

        $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/role/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'coordinator_passkey_required');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => UserRole::NonCoordinator->value,
        ]);
    }

    public function test_admin_cannot_assign_admin_role_through_operational_role_endpoint(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $target = User::factory()->create([
            'role' => UserRole::Volunteer->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::Admin->value,
            ])
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => UserRole::Volunteer->value,
        ]);
    }

    public function test_admin_cannot_change_own_role(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $admin->id), [
                'role' => UserRole::Volunteer->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_role');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'role' => UserRole::Admin->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserRoleChanged->value,
            'resource_type' => 'user',
            'resource_id' => $admin->id,
            'outcome' => AuditEventOutcome::Denied->value,
        ]);
    }

    public function test_admin_cannot_change_other_admin_role_in_this_phase(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $target = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::Coordinator->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'admin_account_role_locked');

        $this->assertDatabaseHas('users', [
            'id' => $target->id,
            'role' => UserRole::Admin->value,
        ]);
    }

    public function test_non_admin_cannot_assign_roles(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
        ]);
        $target = User::factory()->create([
            'role' => UserRole::Volunteer->value,
        ]);

        $this->actingAs($coordinator)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::NonCoordinator->value,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden_role');
    }

    public function test_admin_without_passkey_cannot_start_role_assignment(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $target = User::factory()->create([
            'role' => UserRole::Volunteer->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/role/options', $target->id), [
                'role' => UserRole::NonCoordinator->value,
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'No registered security keys are available for role assignment.',
            ]);
    }

    public function test_admin_can_reset_user_totp_after_passkey_challenge(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/recovery/options', $target->id), [
                'action' => 'reset_totp',
                'reason' => 'User lost authenticator',
            ])
            ->assertOk()
            ->assertJsonPath('recovery.action', 'reset_totp')
            ->assertJsonPath('recovery.targetUserId', $target->id);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(11);
        });

        $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/recovery/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'TOTP enrollment reset successfully.',
                'user' => [
                    'id' => $target->id,
                    'enrollment' => [
                        'enrolled' => [
                            'totp' => false,
                        ],
                        'missing' => [
                            'totp' => true,
                        ],
                    ],
                ],
            ]);

        $target->refresh();
        $this->assertFalse($target->two_factor_enabled);
        $this->assertNull($target->two_factor_secret);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserRecoveryChallengeStarted->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserTotpReset->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);

        $recoveryEvent = AuditEvent::query()
            ->where('event_type', AuditEventType::AdminUserTotpReset->value)
            ->where('resource_id', $target->id)
            ->sole();

        $this->assertSame('User lost authenticator', $recoveryEvent->metadata['reason']);
    }

    public function test_admin_can_revoke_user_passkeys_after_passkey_challenge(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);
        $this->createPasskey($target, 'credential-target');

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/recovery/options', $target->id), [
                'action' => 'revoke_passkeys',
            ])
            ->assertOk()
            ->assertJsonPath('recovery.action', 'revoke_passkeys')
            ->assertJsonPath('recovery.targetUserId', $target->id);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(12);
        });

        $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/recovery/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Passkeys revoked successfully.',
                'user' => [
                    'id' => $target->id,
                    'enrollment' => [
                        'enrolled' => [
                            'passkey' => false,
                        ],
                        'missing' => [
                            'passkey' => true,
                        ],
                        'passkeyCount' => 0,
                    ],
                ],
            ]);

        $this->assertSame(0, $target->webauthnCredentials()->count());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserPasskeysRevoked->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_issue_password_reset_after_passkey_challenge_and_user_can_complete_it(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'email' => 'reset-target@casamonarca.local',
            'password' => 'old-password',
            'role' => UserRole::Volunteer->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/recovery/options', $target->id), [
                'action' => 'reset_password',
                'reason' => 'User forgot password',
            ])
            ->assertOk()
            ->assertJsonPath('recovery.action', 'reset_password')
            ->assertJsonPath('recovery.targetUserId', $target->id);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(13);
        });

        $response = $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/recovery/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Password reset link issued successfully.',
                'passwordReset' => [
                    'email' => $target->email,
                ],
            ]);

        $token = $response->json('passwordReset.token');
        $this->assertIsString($token);
        $this->assertStringStartsWith('/reset-password?', (string) $response->json('passwordReset.resetPath'));
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $target->email,
        ]);

        $target->refresh();
        $this->assertFalse(Hash::check('old-password', $target->password));

        $this->postJson('/login', [
            'email' => $target->email,
            'password' => 'old-password',
        ])->assertUnprocessable();

        $this->postJson('/password-reset/complete', [
            'email' => $target->email,
            'token' => $token,
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
            ->assertOk()
            ->assertJson([
                'message' => 'Password reset successfully. You can now sign in with the new password.',
            ]);

        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $target->email)->count());
        $this->postJson('/login', [
            'email' => $target->email,
            'password' => 'new-password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserPasswordResetIssued->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $target->id,
            'event_type' => AuditEventType::AuthPasswordResetCompleted->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_cannot_start_recovery_for_own_account_or_admin_account(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $otherAdmin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/recovery/options', $admin->id), [
                'action' => 'reset_totp',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_recover_own_account');

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/recovery/options', $otherAdmin->id), [
                'action' => 'revoke_passkeys',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'admin_account_recovery_locked');
    }

    public function test_admin_without_passkey_cannot_start_account_recovery(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $target = User::factory()->create([
            'role' => UserRole::Volunteer->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/recovery/options', $target->id), [
                'action' => 'reset_totp',
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'No registered security keys are available for account recovery.',
            ]);
    }

    public function test_admin_can_suspend_user_after_passkey_challenge(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
        ]);

        $optionsResponse = $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/status/options', $target->id), [
                'action' => 'suspend',
                'reason' => 'Access revoked by test',
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Account status authentication challenge created.',
                'statusChange' => [
                    'action' => 'suspend',
                    'targetUserId' => $target->id,
                    'previousStatus' => UserStatus::Active->value,
                ],
            ])
            ->assertJsonStructure([
                'challengeIntent' => [
                    'id',
                    'purpose',
                    'status',
                    'expiresAt',
                ],
            ]);

        $challengeIntentId = $optionsResponse->json('challengeIntent.id');

        $this->assertIsString($challengeIntentId);
        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $challengeIntentId,
            'purpose' => 'admin.user.status_change',
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'actor_user_id' => $admin->id,
            'target_type' => 'user',
            'target_id' => $target->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserStatusChangeChallengeStarted->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(13);
        });

        $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/status/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'User account suspended successfully.',
                'user' => [
                    'id' => $target->id,
                    'status' => UserStatus::Suspended->value,
                    'suspensionReason' => 'Access revoked by test',
                ],
            ]);

        $target->refresh();
        $this->assertTrue($target->isSuspended());
        $this->assertNotNull($target->suspended_at);
        $this->assertSame($admin->id, $target->suspended_by_user_id);
        $this->assertSame('Access revoked by test', $target->suspension_reason);
        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $challengeIntentId,
            'status' => SecurityChallengeIntent::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserDisabled->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_reactivate_suspended_user_after_passkey_challenge(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::Volunteer->value,
            'status' => UserStatus::Suspended->value,
            'suspended_at' => now()->subHour(),
            'suspended_by_user_id' => $admin->id,
            'suspension_reason' => 'Temporary suspension',
        ]);

        $optionsResponse = $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/status/options', $target->id), [
                'action' => 'reactivate',
            ])
            ->assertOk()
            ->assertJsonStructure([
                'challengeIntent' => [
                    'id',
                    'purpose',
                    'status',
                    'expiresAt',
                ],
            ])
            ->assertJsonPath('statusChange.action', 'reactivate')
            ->assertJsonPath('statusChange.previousStatus', UserStatus::Suspended->value);

        $challengeIntentId = $optionsResponse->json('challengeIntent.id');

        $this->assertIsString($challengeIntentId);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(14);
        });

        $this->actingAs($admin)
            ->postJson(
                sprintf('/admin/users/%d/status/verify', $target->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'User account reactivated successfully.',
                'user' => [
                    'id' => $target->id,
                    'status' => UserStatus::Active->value,
                    'suspensionReason' => null,
                ],
            ]);

        $target->refresh();
        $this->assertTrue($target->isActiveAccount());
        $this->assertNull($target->suspended_at);
        $this->assertNull($target->suspended_by_user_id);
        $this->assertNull($target->suspension_reason);
        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $challengeIntentId,
            'status' => SecurityChallengeIntent::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserEnabled->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_cancel_user_status_passkey_challenge(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');
        $target = User::factory()->create([
            'role' => UserRole::Volunteer->value,
        ]);

        $optionsResponse = $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/status/options', $target->id), [
                'action' => 'suspend',
                'reason' => 'Testing cancellation',
            ])
            ->assertOk();

        $challengeIntentId = $optionsResponse->json('challengeIntent.id');

        $this->assertIsString($challengeIntentId);

        $this->actingAs($admin)
            ->postJson(sprintf('/security-challenges/%s/cancel', $challengeIntentId))
            ->assertOk()
            ->assertJson([
                'message' => 'Challenge intent cancelled.',
                'challengeIntent' => [
                    'id' => $challengeIntentId,
                    'status' => SecurityChallengeIntent::STATUS_CANCELLED,
                ],
            ]);

        $target->refresh();
        $this->assertTrue($target->isActiveAccount());
        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $challengeIntentId,
            'status' => SecurityChallengeIntent::STATUS_CANCELLED,
            'target_type' => 'user',
            'target_id' => $target->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::SecurityChallengeCancelled->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
        $this->assertDatabaseMissing('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminUserDisabled->value,
            'resource_type' => 'user',
            'resource_id' => $target->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_view_verification_package_signing_key_summary(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $publicKey = "-----BEGIN PUBLIC KEY-----\ntest\n-----END PUBLIC KEY-----\n";

        config()->set('documents.package_signing.key_id', 'cm-test-key');
        config()->set('documents.package_signing.private_key', 'private-key');
        config()->set('documents.package_signing.public_key', $publicKey);

        $this->actingAs($admin)
            ->getJson('/admin/verification-package-signing-key')
            ->assertOk()
            ->assertJson([
                'message' => 'Verification package signing key loaded successfully.',
                'signingKey' => [
                    'algorithm' => 'RSASSA-PKCS1-v1_5-SHA256',
                    'configured' => true,
                    'keyId' => 'cm-test-key',
                    'publicKeyFingerprint' => hash('sha256', $publicKey),
                ],
            ]);
    }

    public function test_admin_can_rotate_verification_package_signing_key_after_passkey_challenge(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $this->createPasskey($admin, 'credential-admin');

        $this->mock(VerificationPackageSigningKeyService::class, function ($mock): void {
            $mock->shouldReceive('summary')
                ->once()
                ->andReturn([
                    'algorithm' => 'RSASSA-PKCS1-v1_5-SHA256',
                    'configured' => true,
                    'configCached' => false,
                    'envWritable' => true,
                    'keyId' => 'cm-old-key',
                    'privateKeyConfigured' => true,
                    'publicKeyConfigured' => true,
                    'publicKeyFingerprint' => 'old-fingerprint',
                    'rotationSupported' => true,
                ]);
            $mock->shouldReceive('rotate')
                ->once()
                ->with('cm-new-key', 3072)
                ->andReturn([
                    'previous' => [
                        'keyId' => 'cm-old-key',
                        'publicKeyFingerprint' => 'old-fingerprint',
                    ],
                    'current' => [
                        'algorithm' => 'RSASSA-PKCS1-v1_5-SHA256',
                        'configured' => true,
                        'configCached' => false,
                        'envWritable' => true,
                        'keyId' => 'cm-new-key',
                        'privateKeyConfigured' => true,
                        'publicKeyConfigured' => true,
                        'publicKeyFingerprint' => 'new-fingerprint',
                        'rotationSupported' => true,
                    ],
                    'bits' => 3072,
                ]);
        });

        $this->actingAs($admin)
            ->postJson('/admin/verification-package-signing-key/rotation/options', [
                'keyId' => 'cm-new-key',
                'bits' => 3072,
                'reason' => 'Routine staging key rotation',
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Package signing key rotation authentication challenge created.',
                'rotation' => [
                    'previousKeyId' => 'cm-old-key',
                    'previousPublicKeyFingerprint' => 'old-fingerprint',
                    'targetKeyId' => 'cm-new-key',
                    'bits' => 3072,
                ],
            ]);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(15);
        });

        $this->actingAs($admin)
            ->postJson(
                '/admin/verification-package-signing-key/rotation/verify',
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Verification package signing key rotated successfully.',
                'signingKey' => [
                    'keyId' => 'cm-new-key',
                    'publicKeyFingerprint' => 'new-fingerprint',
                ],
                'previousSigningKey' => [
                    'keyId' => 'cm-old-key',
                    'publicKeyFingerprint' => 'old-fingerprint',
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminPackageSigningKeyRotationChallengeStarted->value,
            'resource_type' => 'package_signing_key',
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AdminPackageSigningKeyRotated->value,
            'resource_type' => 'package_signing_key',
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_cannot_change_own_status_or_admin_status_in_this_phase(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $otherAdmin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/status/options', $admin->id), [
                'action' => 'suspend',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'cannot_change_own_status');

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/users/%d/status/options', $otherAdmin->id), [
                'action' => 'suspend',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'admin_account_status_locked');
    }

    public function test_suspended_user_cannot_login_or_use_protected_routes(): void
    {
        $user = User::factory()->create([
            'email' => 'suspended@casamonarca.local',
            'role' => UserRole::NonCoordinator->value,
            'status' => UserStatus::Suspended->value,
        ]);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');

        $this->actingAs($user)
            ->getJson('/documents')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'account_suspended');
    }

    private function createPasskey(User $user, string $credentialId): void
    {
        $user->webauthnCredentials()->create([
            'credential_id' => $credentialId,
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Admin key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertionPayload(string $credentialId): array
    {
        return [
            'id' => $credentialId,
            'rawId' => $credentialId,
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => 'client-data',
                'authenticatorData' => 'authenticator-data',
                'signature' => 'signature',
            ],
        ];
    }
}
