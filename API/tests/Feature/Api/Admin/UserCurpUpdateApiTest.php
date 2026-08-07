<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserCurpUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_an_account_curp_after_passkey_step_up(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $target = User::factory()->create();
        $this->createPasskey($admin);

        $this->actingAs($admin)
            ->postJson("/admin/users/{$target->id}/curp/options", ['curp' => ' sabc560626mdflrn01 '])
            ->assertOk()
            ->assertJsonPath('curpUpdate.targetCurp', 'SABC560626MDFLRN01');

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')->once()->andReturn(4);
        });

        $this->actingAs($admin)
            ->postJson("/admin/users/{$target->id}/curp/verify", $this->assertionPayload())
            ->assertOk()
            ->assertJsonPath('user.curp', 'SABC560626MDFLRN01');

        $this->assertDatabaseHas('users', ['id' => $target->id, 'curp' => 'SABC560626MDFLRN01']);
        $event = AuditEvent::query()->where('event_type', AuditEventType::AdminUserCurpChanged->value)->sole();
        $this->assertSame(AuditEventOutcome::Success->value, $event->outcome);
        $this->assertTrue($event->metadata['targetCurpPresent']);
        $this->assertStringNotContainsString('SABC560626MDFLRN01', json_encode($event->metadata, JSON_THROW_ON_ERROR));
    }

    public function test_admin_can_clear_own_curp(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
            'curp' => 'SABC560626MDFLRN01',
        ]);
        $this->createPasskey($admin);

        $this->actingAs($admin)->postJson("/admin/users/{$admin->id}/curp/options", ['curp' => null])->assertOk();
        $this->mock(WebauthnAssertionService::class, fn ($mock) => $mock->shouldReceive('verifyAssertionPayload')->once()->andReturn(5));
        $this->actingAs($admin)
            ->postJson("/admin/users/{$admin->id}/curp/verify", $this->assertionPayload())
            ->assertOk()
            ->assertJsonPath('user.curp', null);
    }

    public function test_duplicate_and_invalid_curps_are_rejected_before_challenge_creation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $this->createPasskey($admin);
        User::factory()->create(['curp' => 'SABC560626MDFLRN01']);
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->postJson("/admin/users/{$target->id}/curp/options", ['curp' => 'SABC560626MDFLRN01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('curp');
        $this->actingAs($admin)
            ->postJson("/admin/users/{$target->id}/curp/options", ['curp' => 'SABC560626MDFLRN02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('curp');
    }

    public function test_curp_update_rejects_a_stale_target_after_challenge_creation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $target = User::factory()->create();
        $this->createPasskey($admin);

        $this->actingAs($admin)
            ->postJson("/admin/users/{$target->id}/curp/options", ['curp' => 'SABC560626MDFLRN01'])
            ->assertOk();
        $target->forceFill(['curp' => 'PELJ900101HDFRNS01'])->save();

        $this->actingAs($admin)
            ->postJson("/admin/users/{$target->id}/curp/verify", $this->assertionPayload())
            ->assertConflict()
            ->assertJsonPath('error.code', 'curp_changed');
    }

    public function test_non_admin_cannot_update_curp(): void
    {
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $target = User::factory()->create();

        $this->actingAs($coordinator)
            ->postJson("/admin/users/{$target->id}/curp/options", ['curp' => 'SABC560626MDFLRN01'])
            ->assertForbidden();
    }

    private function createPasskey(User $user): void
    {
        $user->webauthnCredentials()->create([
            'credential_id' => 'credential-admin',
            'public_key' => app(Base64UrlService::class)->encode(random_bytes(48)),
            'public_key_algorithm' => -7,
            'name' => 'Admin key',
            'transports' => ['usb'],
            'attestation_object' => app(Base64UrlService::class)->encode(random_bytes(32)),
            'client_data_json' => app(Base64UrlService::class)->encode(random_bytes(32)),
        ]);
    }

    /** @return array<string, mixed> */
    private function assertionPayload(): array
    {
        return [
            'id' => 'credential-admin',
            'rawId' => 'credential-admin',
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => 'client-data',
                'authenticatorData' => 'authenticator-data',
                'signature' => 'signature',
            ],
        ];
    }
}
