<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TotpEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_totp_enrollment_options_require_authentication(): void
    {
        $this->postJson('/totp/enroll/options')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_totp_enrollment_verify_requires_authentication(): void
    {
        $this->postJson('/totp/enroll/verify', [
            'code' => '123456',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_coordinator_can_enroll_totp_and_receive_updated_user_payload(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $optionsResponse = $this->actingAs($coordinator)
            ->postJson('/totp/enroll/options')
            ->assertOk()
            ->assertJson([
                'message' => 'TOTP enrollment challenge created.',
            ]);

        $secret = (string) $optionsResponse->json('enrollment.secret');
        $code = app(TotpService::class)->currentCode($secret);

        $this->actingAs($coordinator)
            ->postJson('/totp/enroll/verify', [
                'code' => $code,
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'TOTP enrolled successfully.',
                'user' => [
                    'id' => $coordinator->id,
                    'role' => UserRole::Coordinator->value,
                    'capabilities' => [
                        'security' => [
                            'enrolled' => [
                                'totp' => true,
                            ],
                        ],
                    ],
                ],
            ]);

        $coordinator->refresh();

        $this->assertTrue($coordinator->hasTotpEnabled());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $coordinator->id,
            'event_type' => AuditEventType::AuthTotpEnrollmentStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $coordinator->id,
            'event_type' => AuditEventType::AuthTotpEnrolled->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_totp_enrollment_verify_rejects_invalid_code(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
        ]);

        $this->actingAs($coordinator)
            ->postJson('/totp/enroll/options')
            ->assertOk();

        $this->actingAs($coordinator)
            ->postJson('/totp/enroll/verify', [
                'code' => '000000',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'code',
            ]);

        $coordinator->refresh();
        $this->assertFalse($coordinator->hasTotpEnabled());
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $coordinator->id,
            'event_type' => AuditEventType::AuthTotpEnrollmentFailed->value,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
    }
}

