<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrantRegistryPdfDownloadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_start_or_verify_a_registry_pdf_download(): void
    {
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = $this->entry($coordinator);

        $this->actingAsEnrolled($coordinator)
            ->postJson("/registry/migrants/{$entry->id}/pdf/options")
            ->assertForbidden();
        $this->actingAsEnrolled($coordinator)
            ->postJson("/registry/migrants/{$entry->id}/pdf/verify", $this->assertionPayload('unused'))
            ->assertForbidden();
    }

    public function test_admin_downloads_current_registry_pdf_after_passkey_verification(): void
    {
        $admin = User::factory()->create([
            'curp' => 'GODE561231HDFABC09',
            'role' => UserRole::Admin->value,
        ]);
        $entry = $this->entry($admin);
        MigrantRegistrySignature::query()->create([
            'registry_entry_id' => $entry->id,
            'actor_user_id' => $admin->id,
            'actor_role' => UserRole::Admin->value,
            'action_type' => 'approve',
            'algorithm' => 'webauthn',
            'signature_payload' => '{}',
            'public_key_ref' => 'credential-admin',
            'verified_at' => now(),
        ]);
        MigrantRegistryStatusHistory::query()->create([
            'registry_entry_id' => $entry->id,
            'from_status' => MigrantRegistryService::STATUS_PENDING_APPROVAL,
            'to_status' => MigrantRegistryService::STATUS_APPROVED,
            'changed_by' => $admin->id,
            'changed_by_role' => UserRole::Admin->value,
            'reason' => 'Expediente completo',
        ]);
        MigrantRegistryDocument::query()->create([
            'registry_entry_id' => $entry->id,
            'label' => 'Identificación',
            'original_file_name' => 'pasaporte.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'storage_path' => 'not-read-by-export.pdf',
            'uploaded_by' => $admin->id,
            'uploaded_by_role' => UserRole::Admin->value,
        ]);

        $this->actingAsEnrolled($admin)
            ->postJson("/registry/migrants/{$entry->id}/pdf/options")
            ->assertOk()
            ->assertJsonPath('challengeIntent.purpose', 'migrant.registry.pdf.download');

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')->once()->andReturn(5);
        });

        $response = $this->actingAsEnrolled($admin)->postJson(
            "/registry/migrants/{$entry->id}/pdf/verify",
            $this->assertionPayload("enrollment-credential-{$admin->id}"),
        );

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', "attachment; filename=\"registro-migrante-{$entry->id}.pdf\"");
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertDatabaseHas('security_challenge_intents', [
            'actor_user_id' => $admin->id,
            'purpose' => 'migrant.registry.pdf.download',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'event_type' => AuditEventType::MigrantRegistryPdfDownloaded->value,
            'resource_id' => $entry->id,
        ]);
    }

    public function test_registry_change_after_challenge_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $entry = $this->entry($admin);

        $this->actingAsEnrolled($admin)
            ->postJson("/registry/migrants/{$entry->id}/pdf/options")
            ->assertOk();

        $entry->forceFill(['current_status' => MigrantRegistryService::STATUS_APPROVED])->save();

        $this->actingAsEnrolled($admin)
            ->postJson(
                "/registry/migrants/{$entry->id}/pdf/verify",
                $this->assertionPayload("enrollment-credential-{$admin->id}"),
            )
            ->assertConflict()
            ->assertJsonPath('message', 'The migrant registration changed after authentication started. Reload and try again.');
    }

    public function test_draft_registry_pdf_is_not_available(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $entry = $this->entry($admin);
        $entry->forceFill(['current_status' => MigrantRegistryService::STATUS_DRAFT])->save();

        $this->actingAsEnrolled($admin)
            ->postJson("/registry/migrants/{$entry->id}/pdf/options")
            ->assertNotFound();
    }

    private function actingAsEnrolled(User $user): static
    {
        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ])->save();

        WebauthnCredential::query()->firstOrCreate(
            ['credential_id' => "enrollment-credential-{$user->id}"],
            [
                'user_id' => $user->id,
                'public_key' => 'public-key',
                'public_key_algorithm' => -7,
                'name' => 'Enrollment key',
                'sign_count' => 0,
                'transports' => ['internal'],
                'attestation_object' => 'attestation',
                'client_data_json' => 'client-data',
            ],
        );

        return $this->actingAs($user);
    }

    private function entry(User $creator): MigrantRegistryEntry
    {
        return MigrantRegistryEntry::query()->create([
            'created_by' => $creator->id,
            'created_by_role' => $creator->role?->value,
            'current_status' => MigrantRegistryService::STATUS_PENDING_REVIEW,
            'current_assignee_role' => UserRole::NonCoordinator->value,
            'pending_action' => MigrantRegistryService::ACTION_CREATE,
            'payload_json' => [
                'fullName' => 'María Hernández',
                'countryOfOrigin' => 'Honduras',
            ],
            'pending_payload_json' => ['fullName' => 'Nombre todavía no aprobado'],
        ]);
    }

    /** @return array<string, mixed> */
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
