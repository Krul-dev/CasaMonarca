<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\AccountInvite;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountInviteApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_invite_creation_requires_authentication(): void
    {
        $this->postJson('/admin/invites', [
            'email' => 'coordinator@casamonarca.local',
            'role' => UserRole::Coordinator->value,
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_admin_can_create_coordinator_invite_link(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/invites', [
                'email' => 'coordinator@casamonarca.local',
                'role' => UserRole::Coordinator->value,
            ])
            ->assertCreated()
            ->assertJson([
                'message' => 'Invite draft created successfully.',
                'invite' => [
                    'email' => 'coordinator@casamonarca.local',
                    'role' => UserRole::Coordinator->value,
                    'status' => 'draft',
                    'invitedBy' => [
                        'id' => $admin->id,
                        'email' => $admin->email,
                        'role' => UserRole::Admin->value,
                    ],
                ],
            ]);

        $response->assertJsonMissingPath('invite.registrationToken');
        $response->assertJsonMissingPath('invite.registrationPath');

        $invite = AccountInvite::query()->sole();
        $this->assertDatabaseHas('account_invites', [
            'id' => $invite->id,
            'email' => 'coordinator@casamonarca.local',
            'role' => UserRole::Coordinator->value,
            'invited_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AccountInviteCreated->value,
            'resource_type' => 'account_invite',
            'resource_id' => $invite->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_create_non_coordinator_invite_link(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/invites', [
                'email' => 'noncoordinator@casamonarca.local',
                'role' => UserRole::NonCoordinator->value,
            ])
            ->assertCreated()
            ->assertJson([
                'message' => 'Invite draft created successfully.',
                'invite' => [
                    'email' => 'noncoordinator@casamonarca.local',
                    'role' => UserRole::NonCoordinator->value,
                    'status' => 'draft',
                    'invitedBy' => [
                        'id' => $admin->id,
                        'email' => $admin->email,
                        'role' => UserRole::Admin->value,
                    ],
                ],
            ]);

        $response->assertJsonMissingPath('invite.registrationToken');
        $response->assertJsonMissingPath('invite.registrationPath');

        $invite = AccountInvite::query()->sole();
        $this->assertDatabaseHas('account_invites', [
            'id' => $invite->id,
            'email' => 'noncoordinator@casamonarca.local',
            'role' => UserRole::NonCoordinator->value,
            'invited_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AccountInviteCreated->value,
            'resource_type' => 'account_invite',
            'resource_id' => $invite->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_create_admin_invite_only_in_dev_environment(): void
    {
        config(['app.temporary_dev_admin_invites' => true]);
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $response = $this->actingAs($admin)
            ->postJson('/admin/invites', [
                'email' => 'temporary-admin@casamonarca.local',
                'role' => UserRole::Admin->value,
            ])
            ->assertCreated()
            ->assertJson([
                'message' => 'Temporary dev-only admin invite draft created successfully.',
                'invite' => [
                    'email' => 'temporary-admin@casamonarca.local',
                    'role' => UserRole::Admin->value,
                    'status' => 'draft',
                ],
            ]);

        $response->assertJsonMissingPath('invite.registrationToken');
        $response->assertJsonMissingPath('invite.registrationPath');
        $this->assertDatabaseHas('account_invites', [
            'email' => 'temporary-admin@casamonarca.local',
            'role' => UserRole::Admin->value,
            'invited_by_user_id' => $admin->id,
        ]);
    }

    public function test_admin_invite_role_is_rejected_outside_dev_environment(): void
    {
        config(['app.temporary_dev_admin_invites' => false]);
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $this->actingAs($admin)
            ->postJson('/admin/invites', [
                'email' => 'admin-rejected@casamonarca.local',
                'role' => UserRole::Admin->value,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['role']);

        $this->assertDatabaseCount('account_invites', 0);
    }

    public function test_coordinator_can_create_volunteer_invite_link(): void
    {
        $coordinator = $this->createEnrolledCoordinator();

        $this->actingAs($coordinator)
            ->postJson('/admin/invites', [
                'email' => 'volunteer@casamonarca.local',
                'role' => UserRole::Volunteer->value,
            ])
            ->assertCreated()
            ->assertJsonPath('invite.status', 'draft')
            ->assertJsonPath('invite.role', UserRole::Volunteer->value);
    }

    public function test_coordinator_can_create_non_coordinator_invite_link(): void
    {
        $coordinator = $this->createEnrolledCoordinator();

        $this->actingAs($coordinator)
            ->postJson('/admin/invites', [
                'email' => 'noncoordinator-by-coordinator@casamonarca.local',
                'role' => UserRole::NonCoordinator->value,
            ])
            ->assertCreated()
            ->assertJsonPath('invite.status', 'draft')
            ->assertJsonPath('invite.role', UserRole::NonCoordinator->value);
    }

    public function test_coordinator_cannot_create_coordinator_invite_link(): void
    {
        $coordinator = $this->createEnrolledCoordinator();

        $this->actingAs($coordinator)
            ->postJson('/admin/invites', [
                'email' => 'coordinator2@casamonarca.local',
                'role' => UserRole::Coordinator->value,
            ])
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_invite_role',
                    'allowedRolesToInvite' => [
                        UserRole::NonCoordinator->value,
                        UserRole::Volunteer->value,
                    ],
                    'attemptedRole' => UserRole::Coordinator->value,
                ],
            ]);

        $this->assertDatabaseCount('account_invites', 0);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $coordinator->id,
            'event_type' => AuditEventType::AccountInviteCreationDenied->value,
            'outcome' => AuditEventOutcome::Denied->value,
        ]);
    }

    public function test_admin_can_verify_and_issue_coordinator_invite_link(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $createResponse = $this->actingAs($admin)
            ->postJson('/admin/invites', [
                'email' => 'coordinator-verified@casamonarca.local',
                'role' => UserRole::Coordinator->value,
            ])
            ->assertCreated();

        $inviteId = (int) $createResponse->json('invite.id');

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/invites/%d/verify-out-of-band', $inviteId), [
                'method' => 'phone',
                'note' => 'Identity verified by phone call.',
            ])
            ->assertOk()
            ->assertJsonPath('invite.status', 'verified')
            ->assertJsonPath('invite.verificationMethod', 'phone');

        $issueResponse = $this->actingAs($admin)
            ->postJson(sprintf('/admin/invites/%d/issue-link', $inviteId), [
                'expiresInHours' => 12,
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Invite link issued successfully.',
            ])
            ->assertJsonPath('invite.status', 'issued');

        $token = (string) $issueResponse->json('invite.registrationToken');
        $this->assertNotSame('', $token);

        $invite = AccountInvite::query()->findOrFail($inviteId);
        $this->assertSame(hash('sha256', $token), $invite->token_hash);
        $this->assertNotNull($invite->verified_out_of_band_at);
        $this->assertNotNull($invite->issued_at);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AccountInviteVerified->value,
            'resource_type' => 'account_invite',
            'resource_id' => $inviteId,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AccountInviteLinkIssued->value,
            'resource_type' => 'account_invite',
            'resource_id' => $inviteId,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_coordinator_cannot_issue_invite_link_before_out_of_band_verification(): void
    {
        $coordinator = $this->createEnrolledCoordinator();

        $createResponse = $this->actingAs($coordinator)
            ->postJson('/admin/invites', [
                'email' => 'volunteer-stepup@casamonarca.local',
                'role' => UserRole::Volunteer->value,
            ])
            ->assertCreated();

        $inviteId = (int) $createResponse->json('invite.id');

        $this->actingAs($coordinator)
            ->postJson(sprintf('/admin/invites/%d/issue-link', $inviteId))
            ->assertConflict()
            ->assertJson([
                'message' => 'Out-of-band verification is required before issuing the invite link.',
                'error' => [
                    'code' => 'invite_not_verified',
                ],
            ]);
    }

    public function test_coordinator_can_verify_and_issue_own_non_coordinator_invite_link(): void
    {
        $coordinator = $this->createEnrolledCoordinator();

        $createResponse = $this->actingAs($coordinator)
            ->postJson('/admin/invites', [
                'email' => 'noncoordinator-stepup@casamonarca.local',
                'role' => UserRole::NonCoordinator->value,
            ])
            ->assertCreated();

        $inviteId = (int) $createResponse->json('invite.id');

        $this->actingAs($coordinator)
            ->postJson(sprintf('/admin/invites/%d/verify-out-of-band', $inviteId), [
                'method' => 'phone',
            ])
            ->assertOk()
            ->assertJsonPath('invite.status', 'verified');

        $this->actingAs($coordinator)
            ->postJson(sprintf('/admin/invites/%d/issue-link', $inviteId))
            ->assertOk()
            ->assertJsonPath('invite.status', 'issued')
            ->assertJsonPath('invite.role', UserRole::NonCoordinator->value);
    }

    public function test_coordinator_cannot_verify_volunteer_invite_created_by_someone_else(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $coordinator = $this->createEnrolledCoordinator();

        $createResponse = $this->actingAs($admin)
            ->postJson('/admin/invites', [
                'email' => 'volunteer-foreign@casamonarca.local',
                'role' => UserRole::Volunteer->value,
            ])
            ->assertCreated();

        $inviteId = (int) $createResponse->json('invite.id');

        $this->actingAs($coordinator)
            ->postJson(sprintf('/admin/invites/%d/verify-out-of-band', $inviteId), [
                'method' => 'in_person',
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden_invite_access');

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $coordinator->id,
            'event_type' => AuditEventType::AccountInviteVerificationDenied->value,
            'resource_type' => 'account_invite',
            'resource_id' => $inviteId,
            'outcome' => AuditEventOutcome::Denied->value,
        ]);
    }

    public function test_admin_can_revoke_pending_invite(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $invite = AccountInvite::query()->create([
            'email' => 'to-revoke@casamonarca.local',
            'role' => UserRole::Volunteer->value,
            'invited_by_user_id' => $admin->id,
            'token_hash' => hash('sha256', 'token-revoke'),
            'expires_at' => now('UTC')->addDay(),
            'issued_at' => now('UTC')->subMinute(),
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/invites/%d/revoke', $invite->id))
            ->assertOk()
            ->assertJsonPath('invite.status', 'revoked');

        $invite->refresh();
        $this->assertNotNull($invite->revoked_at);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::AccountInviteRevoked->value,
            'resource_type' => 'account_invite',
            'resource_id' => $invite->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_coordinator_can_only_list_own_non_coordinator_and_volunteer_invites(): void
    {
        $coordinator = $this->createEnrolledCoordinator();
        $anotherCoordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
        ]);
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        AccountInvite::query()->create([
            'email' => 'own-volunteer@casamonarca.local',
            'role' => UserRole::Volunteer->value,
            'invited_by_user_id' => $coordinator->id,
            'token_hash' => hash('sha256', 'token-own-volunteer'),
            'expires_at' => now('UTC')->addDay(),
        ]);
        AccountInvite::query()->create([
            'email' => 'own-noncoordinator@casamonarca.local',
            'role' => UserRole::NonCoordinator->value,
            'invited_by_user_id' => $coordinator->id,
            'token_hash' => hash('sha256', 'token-own-noncoordinator'),
            'expires_at' => now('UTC')->addDay(),
        ]);
        AccountInvite::query()->create([
            'email' => 'foreign-volunteer@casamonarca.local',
            'role' => UserRole::Volunteer->value,
            'invited_by_user_id' => $anotherCoordinator->id,
            'token_hash' => hash('sha256', 'token-foreign-volunteer'),
            'expires_at' => now('UTC')->addDay(),
        ]);
        AccountInvite::query()->create([
            'email' => 'admin-created-coordinator@casamonarca.local',
            'role' => UserRole::Coordinator->value,
            'invited_by_user_id' => $admin->id,
            'token_hash' => hash('sha256', 'token-admin-coordinator'),
            'expires_at' => now('UTC')->addDay(),
        ]);

        $response = $this->actingAs($coordinator)
            ->getJson('/admin/invites?limit=50')
            ->assertOk()
            ->assertJsonPath('message', 'Invites loaded successfully.');

        $invites = $response->json('invites');
        $this->assertIsArray($invites);
        $this->assertCount(2, $invites);
        $this->assertSame([
            'own-noncoordinator@casamonarca.local',
            'own-volunteer@casamonarca.local',
        ], collect($invites)->pluck('email')->sort()->values()->all());
        $this->assertSame([
            UserRole::NonCoordinator->value,
            UserRole::Volunteer->value,
        ], collect($invites)->pluck('role')->sort()->values()->all());
    }

    private function createEnrolledCoordinator(): User
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
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

        return $coordinator;
    }
}
