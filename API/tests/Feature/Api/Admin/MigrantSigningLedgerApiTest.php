<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\UserRole;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrantSigningLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_migrant_signatures_grouped_by_registration(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $volunteer = User::factory()->create([
            'name' => 'Volunteer Local',
            'email' => 'volunteer@casamonarca.local',
            'role' => UserRole::Volunteer->value,
        ]);
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $volunteer->id,
            'created_by_role' => UserRole::Volunteer->value,
            'current_status' => 'pending_review',
            'current_assignee_role' => UserRole::NonCoordinator->value,
            'pending_action' => 'create',
            'payload_json' => ['fullName' => 'María Elena Prueba Demo'],
        ]);
        $signature = MigrantRegistrySignature::query()->create([
            'registry_entry_id' => $entry->id,
            'actor_user_id' => $volunteer->id,
            'actor_role' => UserRole::Volunteer->value,
            'action_type' => 'submit',
            'algorithm' => 'webauthn-passkey',
            'signature_payload' => '{}',
            'public_key_ref' => 'credential-volunteer-1',
            'verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/migrant-signing-ledger')
            ->assertOk()
            ->assertJsonPath('message', 'Migrant signing ledger loaded successfully.')
            ->assertJsonPath('registrations.0.id', $entry->id)
            ->assertJsonPath('registrations.0.fullName', 'María Elena Prueba Demo')
            ->assertJsonPath('registrations.0.signatures.0.id', $signature->id)
            ->assertJsonPath('registrations.0.signatures.0.actor.email', $volunteer->email)
            ->assertJsonPath('registrations.0.signatures.0.publicKeyRef', 'credential-volunteer-1')
            ->assertJsonPath('signers.1.email', $volunteer->email)
            ->assertJsonPath('signers.1.signatureCount', 1);
    }

    public function test_non_admin_cannot_view_migrant_signing_ledger(): void
    {
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);

        $this->actingAs($coordinator)
            ->getJson('/admin/migrant-signing-ledger')
            ->assertForbidden();
    }

    public function test_admin_ledger_includes_arco_purged_registrations_and_their_signatures(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $coordinator->id,
            'created_by_role' => UserRole::Coordinator->value,
            'current_status' => 'deleted_by_admin_arco',
            'current_assignee_role' => null,
            'payload_json' => [],
        ]);
        $signature = MigrantRegistrySignature::query()->create([
            'registry_entry_id' => $entry->id,
            'actor_user_id' => $coordinator->id,
            'actor_role' => UserRole::Coordinator->value,
            'action_type' => 'coordinator_approved',
            'algorithm' => 'webauthn-passkey',
            'signature_payload' => '{}',
            'public_key_ref' => 'credential-coordinator-1',
            'verified_at' => now(),
        ]);
        $entry->delete();

        $this->actingAs($admin)
            ->getJson('/admin/migrant-signing-ledger')
            ->assertOk()
            ->assertJsonPath('registrations.0.id', $entry->id)
            ->assertJsonPath('registrations.0.fullName', "Registration #{$entry->id}")
            ->assertJsonPath('registrations.0.status', 'deleted_by_admin_arco')
            ->assertJsonPath('registrations.0.isPurged', true)
            ->assertJsonPath('registrations.0.signatures.0.id', $signature->id)
            ->assertJson(fn ($json) => $json->whereType('registrations.0.purgedAt', 'string')->etc());
    }
}
