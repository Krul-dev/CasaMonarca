<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
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

    public function test_rectification_is_applied_only_after_coordinator_approval(): void
    {
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        $proposal = [...$entry->payload_json, 'departmentState' => 'Chiapas'];
        $arco = $this->service()->create($operator, $entry, 'rectification', 'Correct state', $proposal, $this->signature());

        $this->assertSame('Cortes', $entry->fresh()->payload_json['departmentState']);
        $resolved = $this->service()->coordinatorDecision($coordinator, $arco, 'approve', null, $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_COMPLETED, $resolved->status);
        $this->assertSame('Chiapas', $entry->fresh()->payload_json['departmentState']);
        $this->assertDatabaseHas('migrant_registry_status_history', ['registry_entry_id' => $entry->id, 'arco_request_id' => $arco->id]);
    }

    public function test_access_approval_generates_a_private_pdf(): void
    {
        Storage::fake('local');
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        $arco = $this->service()->create($operator, $entry, 'access', 'Provide my information', null, $this->signature());

        $resolved = $this->service()->coordinatorDecision($coordinator, $arco, 'approve', null, $this->signature());
        $artifact = $resolved->artifact;

        $this->assertNotNull($artifact);
        $this->assertSame('application/pdf', $artifact->mime_type);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get("arco/access/{$arco->id}/{$artifact->filename}"));
    }

    public function test_cancellation_requires_admin_and_purges_personal_payloads(): void
    {
        Storage::fake('local');
        [$operator, $coordinator, $entry] = $this->actorsAndEntry();
        $admin = User::factory()->create(['role' => UserRole::Admin->value]);
        $arco = $this->service()->create($operator, $entry, 'cancellation', 'Cancel the record', null, $this->signature());
        $pendingAdmin = $this->service()->coordinatorDecision($coordinator, $arco, 'approve', null, $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_PENDING_ADMIN, $pendingAdmin->status);
        $resolved = $this->service()->adminDecision($admin, $pendingAdmin, 'approve', 'Authorized cancellation', $this->signature());

        $this->assertSame(MigrantArcoService::STATUS_COMPLETED, $resolved->status);
        $this->assertSame([], MigrantRegistryEntry::withTrashed()->findOrFail($entry->id)->payload_json);
        $this->assertSoftDeleted('migrant_registry_entries', ['id' => $entry->id]);
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

    private function service(): MigrantArcoService
    {
        return $this->app->make(MigrantArcoService::class);
    }

    private function signature(): array
    {
        return ['credentialId' => 'test-passkey', 'assertion' => ['response' => ['signature' => 'test']]];
    }
}
