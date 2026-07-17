<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\UserRole;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrantRegistryDocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_general_registration_detail_does_not_embed_document_metadata(): void
    {
        $volunteer = User::factory()->create(['role' => UserRole::Volunteer->value]);
        $entry = $this->entry($volunteer);
        MigrantRegistryDocument::query()->create($this->documentAttributes($entry, $volunteer));

        $this->actingAs($volunteer)
            ->getJson("/registry/migrants/{$entry->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.documents');
    }

    public function test_upload_limit_is_enforced_under_the_entry_lock(): void
    {
        Storage::fake('local');
        config()->set('features.migrant_documents_max_per_entry', 1);
        $actor = User::factory()->create(['role' => UserRole::NonCoordinator->value]);
        $entry = $this->entry($actor);

        $this->actingAs($actor)->post("/registry/migrants/{$entry->id}/documents", [
            'file' => UploadedFile::fake()->create('first.pdf', 32, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->actingAs($actor)->post("/registry/migrants/{$entry->id}/documents", [
            'file' => UploadedFile::fake()->create('second.pdf', 32, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertDatabaseCount('migrant_registry_documents', 1);
    }

    public function test_registration_and_documents_are_created_in_one_request(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => UserRole::Volunteer->value]);

        $this->actingAs($actor)->post('/registry/migrants', [
            'payload_json' => json_encode($this->validPayload(), JSON_THROW_ON_ERROR),
            'documents' => [UploadedFile::fake()->create('identification.pdf', 32, 'application/pdf')],
            'document_labels' => ['Identification'],
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertDatabaseCount('migrant_registry_entries', 1);
        $this->assertDatabaseHas('migrant_registry_documents', [
            'label' => 'Identification',
            'original_file_name' => 'identification.pdf',
        ]);
    }

    public function test_approved_registration_update_and_documents_are_submitted_in_one_request(): void
    {
        Storage::fake('local');
        $creator = User::factory()->create(['role' => UserRole::Volunteer->value]);
        $actor = User::factory()->create(['role' => UserRole::NonCoordinator->value]);
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $creator->id,
            'created_by_role' => $creator->role?->value,
            'current_status' => MigrantRegistryService::STATUS_APPROVED,
            'current_assignee_role' => null,
            'pending_action' => null,
            'payload_json' => $this->validPayload(),
        ]);
        $updatedPayload = [...$this->validPayload(), 'notes' => 'Updated with supporting document'];

        $this->actingAs($actor)->post("/registry/migrants/{$entry->id}", [
            '_method' => 'PATCH',
            'payload_json' => json_encode($updatedPayload, JSON_THROW_ON_ERROR),
            'documents' => [UploadedFile::fake()->create('supporting-record.pdf', 32, 'application/pdf')],
            'document_labels' => ['Supporting record'],
        ], ['Accept' => 'application/json'])->assertOk();

        $entry->refresh();
        $this->assertSame(MigrantRegistryService::STATUS_PENDING_REVIEW, $entry->current_status);
        $this->assertSame(MigrantRegistryService::ACTION_UPDATE, $entry->pending_action);
        $this->assertSame('Updated with supporting document', $entry->pending_payload_json['notes']);
        $this->assertDatabaseHas('migrant_registry_documents', [
            'registry_entry_id' => $entry->id,
            'label' => 'Supporting record',
            'original_file_name' => 'supporting-record.pdf',
        ]);
    }

    public function test_non_coordinator_cannot_start_document_download_challenge(): void
    {
        $actor = User::factory()->create(['role' => UserRole::NonCoordinator->value]);
        $entry = $this->entry($actor);
        $document = MigrantRegistryDocument::query()->create($this->documentAttributes($entry, $actor));

        $this->actingAs($actor)
            ->postJson("/registry/migrants/{$entry->id}/documents/{$document->id}/download/options")
            ->assertForbidden();
    }

    public function test_document_list_marks_only_files_covered_by_completed_access(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = $this->entry($actor);
        $covered = MigrantRegistryDocument::query()->create($this->documentAttributes($entry, $actor));
        $covered->forceFill(['created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)])->save();
        MigrantArcoRequest::query()->create([
            'registry_entry_id' => $entry->id,
            'requested_by' => $actor->id,
            'requested_by_role' => $actor->role?->value,
            'request_type' => 'access',
            'reason' => 'Access request',
            'status' => 'completed',
            'completed_at' => now()->subMinute(),
        ]);
        $uncovered = MigrantRegistryDocument::query()->create([
            ...$this->documentAttributes($entry, $actor),
            'original_file_name' => 'uploaded-after-access.pdf',
            'storage_path' => 'uploaded-after-access.pdf',
        ]);

        $response = $this->actingAs($actor)
            ->getJson("/registry/migrants/{$entry->id}/documents")
            ->assertOk();

        $documents = collect($response->json('data'))->keyBy('id');
        $this->assertTrue($documents[$covered->id]['arco_access_completed']);
        $this->assertFalse($documents[$uncovered->id]['arco_access_completed']);
    }

    public function test_direct_document_download_endpoint_is_not_available(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = $this->entry($actor);
        $document = MigrantRegistryDocument::query()->create($this->documentAttributes($entry, $actor));

        $this->actingAs($actor)
            ->get("/registry/migrants/{$entry->id}/documents/{$document->id}/download")
            ->assertNotFound();
    }

    public function test_coordinator_downloads_document_after_passkey_verification(): void
    {
        Storage::fake('local');
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        WebauthnCredential::query()->create([
            'user_id' => $actor->id,
            'credential_id' => 'credential-coordinator',
            'public_key' => 'public-key',
            'public_key_algorithm' => -7,
            'name' => 'Coordinator key',
            'sign_count' => 0,
            'transports' => ['internal'],
            'attestation_object' => 'attestation',
            'client_data_json' => 'client-data',
        ]);
        $entry = $this->entry($actor);
        $attributes = $this->documentAttributes($entry, $actor);
        Storage::disk('local')->put($attributes['storage_path'], 'private document');
        $document = MigrantRegistryDocument::query()->create($attributes);

        $this->actingAs($actor)
            ->postJson("/registry/migrants/{$entry->id}/documents/{$document->id}/download/options")
            ->assertOk()
            ->assertJsonPath('challengeIntent.purpose', 'migrant.registry.document.download');

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')->once()->andReturn(7);
        });

        $response = $this->actingAs($actor)->postJson(
            "/registry/migrants/{$entry->id}/documents/{$document->id}/download/verify",
            $this->assertionPayload('credential-coordinator'),
        );

        $response->assertOk();
        $this->assertSame('private document', $response->streamedContent());
        $this->assertDatabaseHas('security_challenge_intents', [
            'actor_user_id' => $actor->id,
            'purpose' => 'migrant.registry.document.download',
            'status' => 'succeeded',
        ]);
    }

    private function entry(User $creator): MigrantRegistryEntry
    {
        return MigrantRegistryEntry::query()->create([
            'created_by' => $creator->id,
            'created_by_role' => $creator->role?->value,
            'current_status' => MigrantRegistryService::STATUS_PENDING_REVIEW,
            'current_assignee_role' => UserRole::NonCoordinator->value,
            'pending_action' => MigrantRegistryService::ACTION_CREATE,
            'payload_json' => ['fullName' => 'Document Test'],
        ]);
    }

    /** @return array<string, mixed> */
    private function documentAttributes(MigrantRegistryEntry $entry, User $uploader): array
    {
        return [
            'registry_entry_id' => $entry->id,
            'original_file_name' => 'private-passport.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'sha256' => str_repeat('a', 64),
            'storage_disk' => 'local',
            'storage_path' => 'private-passport.pdf',
            'uploaded_by' => $uploader->id,
            'uploaded_by_role' => $uploader->role?->value,
        ];
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'attentionDate' => '2026-07-14',
            'birthDate' => '1996-03-31',
            'civilStatus' => 'single',
            'countryOfOrigin' => 'Honduras',
            'departmentState' => 'Cortes',
            'firstLastName' => 'Doe',
            'firstName' => 'John',
            'fullName' => 'John Doe',
            'gender' => 'male',
            'notes' => '',
            'phone' => '+52 81 3100 8716',
            'populationGroup' => 'adult',
            'secondLastName' => '',
        ];
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
