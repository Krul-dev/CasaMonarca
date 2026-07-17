<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantArcoService;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MigrantArcoWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_rectification_and_cancellation_are_enabled(): void
    {
        $this->assertSame(
            ['access', 'rectification', 'cancellation'],
            config('features.arco_types'),
        );
    }

    public function test_rectification_is_applied_only_after_coordinator_approval(): void
    {
        Storage::fake('local');
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        [$document, $documentPath] = $this->attachDocument($entry, $operator);
        $proposal = [...$entry->payload_json, 'departmentState' => 'Chiapas'];
        $arco = $this->service()->create($operator, $entry, 'rectification', 'Correct state', $proposal, $this->signature());

        $this->assertSame('Cortes', $entry->fresh()->payload_json['departmentState']);
        $resolved = $this->service()->coordinatorDecision($coordinator, $arco, 'approve', null, $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_COMPLETED, $resolved->status);
        $this->assertSame('Chiapas', $entry->fresh()->payload_json['departmentState']);
        $this->assertNotNull($document->fresh());
        Storage::disk('local')->assertExists($documentPath);
        $this->assertDatabaseHas('migrant_registry_status_history', ['registry_entry_id' => $entry->id, 'arco_request_id' => $arco->id]);
    }

    public function test_access_approval_generates_a_private_pdf(): void
    {
        Storage::fake('local');
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        [$document] = $this->attachDocument($entry, $operator);
        $arco = $this->service()->create($operator, $entry, 'access', 'Provide my information', null, $this->signature());

        $resolved = $this->service()->coordinatorDecision($coordinator, $arco, 'approve', null, $this->signature());
        $artifact = $resolved->artifact;

        $this->assertNotNull($artifact);
        $this->assertSame('application/pdf', $artifact->mime_type);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get("arco/access/{$arco->id}/{$artifact->filename}"));
        $html = view('arco.access-pdf', [
            'arco' => $resolved->load(['registryEntry.documents', 'requester', 'signatures', 'statusHistory']),
        ])->render();
        $this->assertStringContainsString($document->original_file_name, $html);
        $this->assertStringContainsString($document->sha256, $html);
    }

    public function test_cancellation_requires_admin_and_purges_personal_payloads(): void
    {
        Storage::fake('local');
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $documentPath = "migrant-registry/{$entry->id}/documents/identity.pdf";
        Storage::disk('local')->put($documentPath, 'identity');
        $document = MigrantRegistryDocument::query()->create([
            'registry_entry_id' => $entry->id,
            'original_file_name' => 'identity.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'identity'),
            'storage_disk' => 'local',
            'storage_path' => $documentPath,
            'uploaded_by' => $operator->id,
            'uploaded_by_role' => $operator->role?->value,
        ]);
        $arco = $this->service()->create($operator, $entry, 'cancellation', 'Cancel the record', null, $this->signature());
        $pendingAdmin = $this->service()->coordinatorDecision($coordinator, $arco, 'approve', null, $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_PENDING_ADMIN, $pendingAdmin->status);
        $resolved = $this->service()->adminDecision($admin, $pendingAdmin, 'approve', 'Authorized cancellation', $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_COMPLETED, $resolved->status);
        $this->assertSame([], MigrantRegistryEntry::withTrashed()->findOrFail($entry->id)->payload_json);
        $this->assertSoftDeleted('migrant_registry_entries', ['id' => $entry->id]);
        $this->assertSoftDeleted('migrant_registry_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($documentPath);
        $this->assertDatabaseHas('audit_events', ['event_type' => AuditEventType::MigrantArcoCancellationExecuted->value]);
    }

    public function test_rejection_preserves_the_registry_payload(): void
    {
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        $arco = $this->service()->create($operator, $entry, 'cancellation', 'Cancel', null, $this->signature());
        $resolved = $this->service()->coordinatorDecision($coordinator, $arco, 'reject', 'Insufficient identity evidence', $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_REJECTED, $resolved->status);
        $this->assertSame('Ana Lopez', $entry->fresh()->payload_json['fullName']);
    }

    private function actorsAndEntry(): array
    {
        $operator = User::factory()->create(['role' => UserRole::NonCoordinator->value]);
        $coordinator = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $operator->id, 'created_by_role' => UserRole::NonCoordinator->value,
            'current_status' => MigrantRegistryService::STATUS_APPROVED, 'current_assignee_role' => null,
            'payload_json' => ['fullName' => 'Ana Lopez', 'departmentState' => 'Cortes'],
        ]);

        return [$operator, $coordinator, $entry];
    }

    /** @return array{MigrantRegistryDocument, string} */
    private function attachDocument(MigrantRegistryEntry $entry, User $uploader): array
    {
        $path = "migrant-registry/{$entry->id}/documents/identity.pdf";
        Storage::disk('local')->put($path, 'identity');
        $document = MigrantRegistryDocument::query()->create([
            'registry_entry_id' => $entry->id,
            'label' => 'Identification',
            'original_file_name' => 'identity.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'identity'),
            'storage_disk' => 'local',
            'storage_path' => $path,
            'uploaded_by' => $uploader->id,
            'uploaded_by_role' => $uploader->role?->value,
        ]);

        return [$document, $path];
    }

    private function service(): MigrantArcoService
    {
        return $this->app->make(MigrantArcoService::class);
    }

    private function signature(): array
    {
        return ['credentialId' => 'test-passkey', 'assertion' => ['response' => ['signature' => 'test']]];
    }
}
