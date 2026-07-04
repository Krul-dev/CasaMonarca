<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequireRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_authorization_check_requires_authentication(): void
    {
        $this->getJson('/admin/authorization-check')
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_admin_authorization_check_returns_forbidden_contract_for_non_admin_role(): void
    {
        $coordinatorUser = User::factory()->create([
            'role' => UserRole::Coordinator->value,
        ]);

        $this->actingAs($coordinatorUser)
            ->getJson('/admin/authorization-check')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_role',
                    'requiredRoles' => ['admin'],
                    'currentRole' => UserRole::Coordinator->value,
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $coordinatorUser->id,
            'event_type' => AuditEventType::AuthAuthorizationDenied->value,
            'outcome' => AuditEventOutcome::Denied->value,
        ]);
    }

    public function test_admin_authorization_check_allows_admin_role(): void
    {
        $adminUser = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);

        $this->actingAs($adminUser)
            ->getJson('/admin/authorization-check')
            ->assertOk()
            ->assertJson([
                'message' => 'Admin authorization check passed.',
                'user' => [
                    'id' => $adminUser->id,
                    'email' => $adminUser->email,
                    'role' => UserRole::Admin->value,
                ],
            ]);
    }
}
