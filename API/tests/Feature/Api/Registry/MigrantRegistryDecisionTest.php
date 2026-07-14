<?php

namespace Tests\Feature\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

class MigrantRegistryDecisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_rejection_marks_a_new_registration_as_rejected(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = $this->pendingEntry($actor, MigrantRegistryService::ACTION_CREATE);

        $resolved = $this->service()->resolveApproval(
            $actor,
            $entry,
            'reject',
            'The registration could not be admitted.',
            $this->signatureData(),
        );

        $this->assertSame(MigrantRegistryService::STATUS_REJECTED, $resolved->current_status);
        $this->assertNull($resolved->pending_action);
        $this->assertNull($resolved->current_assignee_role);
        $this->assertDatabaseHas('migrant_registry_status_history', [
            'registry_entry_id' => $entry->id,
            'from_status' => MigrantRegistryService::STATUS_PENDING_APPROVAL,
            'to_status' => MigrantRegistryService::STATUS_REJECTED,
        ]);
        $this->assertDatabaseHas('migrant_registry_signatures', [
            'registry_entry_id' => $entry->id,
            'action_type' => 'reject',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'resource_id' => $entry->id,
            'event_type' => AuditEventType::MigrantRegistryRejected->value,
        ]);
    }

    public function test_final_rejection_of_a_modification_is_not_reported_as_approved(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = $this->pendingEntry($actor, MigrantRegistryService::ACTION_UPDATE);

        $resolved = $this->service()->resolveApproval(
            $actor,
            $entry,
            'reject',
            'The proposed change was rejected.',
            $this->signatureData(),
        );

        $this->assertSame(MigrantRegistryService::STATUS_REJECTED, $resolved->current_status);
        $this->assertSame('Original name', $resolved->payload_json['fullName']);
        $this->assertNull($resolved->pending_payload_json);
        $this->assertDatabaseHas('migrant_registry_status_history', [
            'registry_entry_id' => $entry->id,
            'to_status' => MigrantRegistryService::STATUS_REJECTED,
        ]);
    }

    public function test_final_approval_of_a_modification_applies_the_pending_payload(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $entry = $this->pendingEntry($actor, MigrantRegistryService::ACTION_UPDATE);

        $resolved = $this->service()->resolveApproval(
            $actor,
            $entry,
            'approve',
            null,
            $this->signatureData(),
        );

        $this->assertSame(MigrantRegistryService::STATUS_APPROVED, $resolved->current_status);
        $this->assertSame('Proposed name', $resolved->payload_json['fullName']);
        $this->assertNull($resolved->pending_payload_json);
        $this->assertDatabaseHas('migrant_registry_status_history', [
            'registry_entry_id' => $entry->id,
            'to_status' => MigrantRegistryService::STATUS_APPROVED,
        ]);
    }

    public function test_bulk_approval_approves_every_selected_registration(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $newRegistration = $this->pendingEntry($actor, MigrantRegistryService::ACTION_CREATE);
        $modification = $this->pendingEntry($actor, MigrantRegistryService::ACTION_UPDATE);
        $service = $this->service();

        $resolved = $service->resolveBulkApproval($actor, [
            ['id' => $newRegistration->id, 'payloadHash' => $service->approvalPayloadHash($newRegistration)],
            ['id' => $modification->id, 'payloadHash' => $service->approvalPayloadHash($modification)],
        ], [
            ...$this->signatureData(),
            'bulkApproval' => true,
        ]);

        $this->assertCount(2, $resolved);
        $this->assertSame(
            [MigrantRegistryService::STATUS_APPROVED, MigrantRegistryService::STATUS_APPROVED],
            $resolved->pluck('current_status')->all(),
        );
        $this->assertSame('Proposed name', $modification->fresh()->payload_json['fullName']);
        $this->assertDatabaseCount('migrant_registry_signatures', 2);
        $this->assertDatabaseCount('migrant_registry_status_history', 2);
    }

    public function test_bulk_approval_is_atomic_when_a_selected_registration_changes(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $firstEntry = $this->pendingEntry($actor, MigrantRegistryService::ACTION_CREATE);
        $secondEntry = $this->pendingEntry($actor, MigrantRegistryService::ACTION_CREATE);
        $service = $this->service();

        try {
            $service->resolveBulkApproval($actor, [
                ['id' => $firstEntry->id, 'payloadHash' => $service->approvalPayloadHash($firstEntry)],
                ['id' => $secondEntry->id, 'payloadHash' => str_repeat('0', 64)],
            ], [
                ...$this->signatureData(),
                'bulkApproval' => true,
            ]);
            $this->fail('A stale batch target should prevent the entire approval.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertSame(MigrantRegistryService::STATUS_PENDING_APPROVAL, $firstEntry->fresh()->current_status);
        $this->assertSame(MigrantRegistryService::STATUS_PENDING_APPROVAL, $secondEntry->fresh()->current_status);
        $this->assertDatabaseCount('migrant_registry_signatures', 0);
        $this->assertDatabaseCount('migrant_registry_status_history', 0);
    }

    public function test_non_coordinator_can_start_an_edit_request_for_an_approved_registration(): void
    {
        $creator = User::factory()->create(['role' => UserRole::Volunteer->value]);
        $requester = User::factory()->create(['role' => UserRole::NonCoordinator->value]);
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $creator->id,
            'created_by_role' => UserRole::Volunteer->value,
            'current_status' => MigrantRegistryService::STATUS_APPROVED,
            'current_assignee_role' => null,
            'payload_json' => ['fullName' => 'Original name'],
        ]);

        $updated = $this->service()->requestUpdate(
            $requester,
            $entry,
            ['fullName' => 'Proposed name'],
        );

        $this->assertSame(MigrantRegistryService::STATUS_PENDING_REVIEW, $updated->current_status);
        $this->assertSame(MigrantRegistryService::ACTION_UPDATE, $updated->pending_action);
        $this->assertSame($requester->id, $updated->pending_requested_by);
        $this->assertSame('Original name', $updated->payload_json['fullName']);
        $this->assertSame('Proposed name', $updated->pending_payload_json['fullName']);
        $this->assertDatabaseHas('migrant_registry_status_history', [
            'registry_entry_id' => $entry->id,
            'from_status' => MigrantRegistryService::STATUS_APPROVED,
            'to_status' => MigrantRegistryService::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_volunteer_cannot_start_an_edit_request_for_an_approved_registration(): void
    {
        $volunteer = User::factory()->create(['role' => UserRole::Volunteer->value]);
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $volunteer->id,
            'created_by_role' => UserRole::Volunteer->value,
            'current_status' => MigrantRegistryService::STATUS_APPROVED,
            'current_assignee_role' => null,
            'payload_json' => ['fullName' => 'Original name'],
        ]);

        try {
            $this->service()->requestUpdate($volunteer, $entry, ['fullName' => 'Bypassed edit']);
            $this->fail('A volunteer should not be able to start an edit request.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(MigrantRegistryService::STATUS_APPROVED, $entry->fresh()->current_status);
        $this->assertNull($entry->fresh()->pending_payload_json);
    }

    private function pendingEntry(User $creator, string $pendingAction): MigrantRegistryEntry
    {
        return MigrantRegistryEntry::query()->create([
            'created_by' => $creator->id,
            'created_by_role' => UserRole::Volunteer->value,
            'current_status' => MigrantRegistryService::STATUS_PENDING_APPROVAL,
            'current_assignee_role' => UserRole::Coordinator->value,
            'pending_action' => $pendingAction,
            'pending_requested_by' => $creator->id,
            'pending_requested_by_role' => UserRole::Volunteer->value,
            'payload_json' => ['fullName' => 'Original name'],
            'pending_payload_json' => $pendingAction === MigrantRegistryService::ACTION_UPDATE
                ? ['fullName' => 'Proposed name']
                : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function signatureData(): array
    {
        return [
            'credentialId' => 'test-credential',
            'intent' => ['decision' => 'test'],
            'assertion' => ['signature' => 'test-signature'],
        ];
    }

    private function service(): MigrantRegistryService
    {
        return app(MigrantRegistryService::class);
    }
}
