<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
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
        ]);

        $csrfResponse = $this->getJson('/csrf-token');

        $response = $this->withHeader('X-CSRF-TOKEN', (string) $csrfResponse->json('csrfToken'))
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'secret-password',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'message' => 'Login successful.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ]);

        $this->assertAuthenticatedAs($user);
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
    }
}
