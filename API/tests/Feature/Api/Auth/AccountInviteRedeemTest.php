<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\AccountInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountInviteRedeemTest extends TestCase
{
    use RefreshDatabase;

    public function test_invite_preview_returns_assigned_email_role_and_enrollment_requirements(): void
    {
        $rawToken = 'invite-token-preview';
        $this->createIssuedInvite(
            token: $rawToken,
            email: 'coordinator@casamonarca.local',
            role: UserRole::Coordinator,
        );

        $this->getJson('/invites/preview?token='.$rawToken)
            ->assertOk()
            ->assertJson([
                'message' => 'Invite link is available.',
                'invite' => [
                    'email' => 'coordinator@casamonarca.local',
                    'role' => UserRole::Coordinator->value,
                    'status' => 'issued',
                ],
                'enrollment' => [
                    'requiresTotp' => true,
                    'requiresPasskey' => true,
                ],
            ]);
    }

    public function test_invite_preview_rejects_expired_tokens_before_registration_form_submit(): void
    {
        $rawToken = 'invite-token-expired-preview';
        $invite = $this->createIssuedInvite(
            token: $rawToken,
            email: 'expired@casamonarca.local',
            role: UserRole::Volunteer,
        );
        $invite->forceFill([
            'expires_at' => now('UTC')->subMinute(),
        ])->save();

        $this->getJson('/invites/preview?token='.$rawToken)
            ->assertStatus(410)
            ->assertJson([
                'message' => 'Invite link is no longer available.',
                'error' => [
                    'code' => 'invite_unavailable',
                    'status' => 'expired',
                ],
            ]);
    }

    public function test_redeem_creates_user_with_invited_role_and_marks_invite_as_used(): void
    {
        $rawToken = 'invite-token-volunteer';
        $invite = $this->createIssuedInvite(
            token: $rawToken,
            email: 'volunteer@casamonarca.local',
            role: UserRole::Volunteer,
        );

        $this->postJson('/invites/redeem', [
            'token' => $rawToken,
            'name' => 'Volunteer Local',
            'email' => 'volunteer@casamonarca.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertCreated()
            ->assertJson([
                'message' => 'Invite redeemed successfully.',
                'user' => [
                    'email' => 'volunteer@casamonarca.local',
                    'name' => 'Volunteer Local',
                    'role' => UserRole::Volunteer->value,
                ],
                'enrollment' => [
                    'requiresTotp' => true,
                    'requiresPasskey' => false,
                ],
            ]);

        $user = User::query()->where('email', 'volunteer@casamonarca.local')->firstOrFail();
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => UserRole::Volunteer->value,
        ]);

        $invite->refresh();
        $this->assertNotNull($invite->used_at);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AccountInviteRedeemed->value,
            'resource_type' => 'account_invite',
            'resource_id' => $invite->id,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_invite_redemption_reports_totp_and_passkey_requirements(): void
    {
        $rawToken = 'invite-token-admin';
        $this->createIssuedInvite(
            token: $rawToken,
            email: 'temporary-admin@casamonarca.local',
            role: UserRole::Admin,
        );

        $this->postJson('/invites/redeem', [
            'token' => $rawToken,
            'name' => 'Temporary Admin',
            'email' => 'temporary-admin@casamonarca.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertCreated()
            ->assertJson([
                'message' => 'Invite redeemed successfully.',
                'user' => [
                    'email' => 'temporary-admin@casamonarca.local',
                    'name' => 'Temporary Admin',
                    'role' => UserRole::Admin->value,
                ],
                'enrollment' => [
                    'requiresTotp' => true,
                    'requiresPasskey' => true,
                ],
            ]);
    }

    public function test_redeem_fails_when_email_does_not_match_invite_email(): void
    {
        $invite = $this->createIssuedInvite(
            token: 'invite-token-email-mismatch',
            email: 'owner@casamonarca.local',
            role: UserRole::Coordinator,
        );

        $this->postJson('/invites/redeem', [
            'token' => 'invite-token-email-mismatch',
            'name' => 'Wrong Owner',
            'email' => 'wrong@casamonarca.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Invite email does not match this registration attempt.',
                'error' => [
                    'code' => 'invite_email_mismatch',
                ],
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => 'wrong@casamonarca.local',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'event_type' => AuditEventType::AccountInviteRedemptionFailed->value,
            'resource_type' => 'account_invite',
            'resource_id' => $invite->id,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
    }

    public function test_redeem_fails_with_invalid_or_expired_token(): void
    {
        $this->postJson('/invites/redeem', [
            'token' => 'non-existent-token',
            'name' => 'Unknown Invite',
            'email' => 'unknown@casamonarca.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'Invite token is invalid or expired.',
                'error' => [
                    'code' => 'invalid_invite_token',
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'event_type' => AuditEventType::AccountInviteRedemptionFailed->value,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
    }

    public function test_redeem_rate_limits_repeated_failures_per_token_and_ip(): void
    {
        $payload = [
            'token' => 'rate-limit-token',
            'name' => 'Repeated Failure',
            'email' => 'repeat@casamonarca.local',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/invites/redeem', $payload)
                ->assertUnprocessable();
        }

        $this->postJson('/invites/redeem', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'invite_redeem_rate_limited');
    }

    private function createIssuedInvite(string $token, string $email, UserRole $role): AccountInvite
    {
        $issuer = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        return AccountInvite::query()->create([
            'email' => $email,
            'role' => $role->value,
            'invited_by_user_id' => $issuer->id,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now('UTC')->addDay(),
            'issued_at' => now('UTC')->subMinute(),
        ]);
    }
}
