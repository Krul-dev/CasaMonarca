<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_reject_and_remove_pending_document(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $uploader = User::factory()->create([
            'role' => UserRole::Coordinator->value,
        ]);
        $document = Document::factory()->create([
            'approved_at' => null,
            'approved_by_user_id' => null,
            'owner_user_id' => $uploader->id,
            'status' => 'pending_approval',
            'title' => 'Rejected upload',
            'uploaded_by_user_id' => $uploader->id,
        ]);
        $revision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'created_by_user_id' => $uploader->id,
            'revision_number' => 1,
            'storage_disk' => 'local',
            'storage_path' => 'documents/approval/rejected-upload.pdf',
            'original_file_name' => 'rejected-upload.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1200,
            'sha256' => str_repeat('a', 64),
            'signature_status' => 'unsigned',
        ]);
        $document->forceFill([
            'current_revision_id' => $revision->id,
        ])->save();
        Storage::disk('local')->put('documents/approval/rejected-upload.pdf', 'payload');

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/document-approvals/%d/reject', $document->id), [
                'reason' => 'Wrong document uploaded.',
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Document rejected and removed successfully.',
                'rejectedDocument' => [
                    'id' => $document->id,
                    'title' => 'Rejected upload',
                    'revisionId' => $revision->id,
                ],
            ]);

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);
        $this->assertDatabaseMissing('document_revisions', [
            'id' => $revision->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentApprovalRejected->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        Storage::disk('local')->assertMissing('documents/approval/rejected-upload.pdf');
    }

    public function test_admin_cannot_reject_document_that_is_not_pending_approval(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $document = Document::factory()->create([
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->postJson(sprintf('/admin/document-approvals/%d/reject', $document->id))
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'document_not_pending_approval');

        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
        ]);
    }
}
