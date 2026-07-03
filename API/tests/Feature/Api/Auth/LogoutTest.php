<?php

namespace Tests\Feature\Api\Auth;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_logout_invalidates_the_authenticated_session(): void
    {
        $user = User::factory()->create();

        $csrfResponse = $this->actingAs($user)->getJson('/csrf-token');

        $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/logout')
            ->assertOk()
            ->assertJson([
                'message' => 'Logout successful.',
            ]);

        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'event_type' => AuditEventType::AuthLogout->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }
}
