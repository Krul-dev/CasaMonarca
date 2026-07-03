<?php

namespace Tests\Feature\Api\Documents;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_volunteer_can_upload_document(): void
    {
        Storage::fake('local');

        $user = $this->createEnrolledUser(UserRole::Volunteer);

        $response = $this->actingAs($user)->post('/documents', [
            'title' => 'Volunteer intake',
            'file' => UploadedFile::fake()->createWithContent(
                'intake-note.txt',
                'confidential intake content',
            ),
        ]);

        $response->assertCreated()
            ->assertJson([
                'message' => 'Document uploaded successfully.',
                'document' => [
                    'title' => 'Volunteer intake',
                    'status' => 'active',
                    'confidentiality' => 'confidential',
                    'owner' => [
                        'id' => $user->id,
                        'email' => $user->email,
                    ],
                    'uploadedBy' => [
                        'id' => $user->id,
                        'email' => $user->email,
                    ],
                    'currentRevision' => [
                        'revisionNumber' => 1,
                        'originalFileName' => 'intake-note.txt',
                        'signatureStatus' => 'unsigned',
                    ],
                ],
            ]);

        $document = Document::query()->with('currentRevision')->sole();

        $this->assertSame($user->id, $document->owner_user_id);
        $this->assertSame($user->id, $document->uploaded_by_user_id);
        $this->assertNotNull($document->currentRevision);

        Storage::disk('local')->assertExists($document->currentRevision->storage_path);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentCreated->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $document->currentRevision->id,
        ]);
    }

    public function test_volunteer_cannot_view_document_index(): void
    {
        $volunteer = $this->createEnrolledUser(UserRole::Volunteer);

        $this->actingAs($volunteer)
            ->getJson('/documents')
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_role',
                    'requiredRoles' => [
                        UserRole::Admin->value,
                        UserRole::Coordinator->value,
                        UserRole::NonCoordinator->value,
                    ],
                    'currentRole' => UserRole::Volunteer->value,
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $volunteer->id,
            'event_type' => AuditEventType::AuthAuthorizationDenied->value,
            'outcome' => AuditEventOutcome::Denied->value,
        ]);
    }

    public function test_non_coordinator_can_load_document_index_and_current_document_material_but_not_old_history(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $viewer = $this->createEnrolledUser(UserRole::NonCoordinator);

        $document = Document::factory()->create([
            'title' => 'Resident permit',
            'owner_user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
        ]);

        $firstStoragePath = sprintf('documents/%d/revisions/1/resident-permit-v1.pdf', $document->id);
        $secondStoragePath = sprintf('documents/%d/revisions/2/resident-permit-v2.pdf', $document->id);

        Storage::disk('local')->put($firstStoragePath, 'pdf-content-v1');
        Storage::disk('local')->put($secondStoragePath, 'pdf-content-v2');

        $firstRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => null,
            'created_by_user_id' => $owner->id,
            'revision_number' => 1,
            'storage_disk' => 'local',
            'storage_path' => $firstStoragePath,
            'original_file_name' => 'resident-permit-v1.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 14,
            'sha256' => hash('sha256', 'pdf-content-v1'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'initial_upload',
            ],
        ]);

        $secondRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $firstRevision->id,
            'created_by_user_id' => $owner->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => $secondStoragePath,
            'original_file_name' => 'resident-permit-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 14,
            'sha256' => hash('sha256', 'pdf-content-v2'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $this->actingAs($viewer)
            ->getJson('/documents')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $document->id,
                'title' => 'Resident permit',
            ]);

        $this->actingAs($viewer)
            ->getJson(sprintf('/documents/%d', $document->id))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $document->id,
                'title' => 'Resident permit',
            ])
            ->assertJsonPath('document.capabilities.canDownloadCurrent', true)
            ->assertJsonPath('document.capabilities.canReadCurrentVerificationBundle', true)
            ->assertJsonPath('document.capabilities.canSignCurrent', false)
            ->assertJsonPath('document.capabilities.canUploadRevision', false)
            ->assertJsonPath('document.capabilities.canDeleteDocument', false)
            ->assertJsonCount(1, 'document.revisions')
            ->assertJsonPath('document.revisions.0.revisionNumber', 2)
            ->assertJsonPath('document.revisions.0.diffMetadata.kind', 'revision_update')
            ->assertJsonPath('document.revisions.0.capabilities.canDownload', true)
            ->assertJsonPath('document.revisions.0.capabilities.canReadVerificationBundle', true)
            ->assertJsonPath('document.revisions.0.capabilities.canSign', false);

        $this->actingAs($viewer)
            ->getJson(sprintf('/documents/%d/verification', $document->id))
            ->assertOk()
            ->assertJson([
                'verification' => [
                    'documentId' => $document->id,
                    'currentRevisionId' => $secondRevision->id,
                    'currentRevisionNumber' => 2,
                    'signatureStatus' => 'unsigned',
                    'hasSignatures' => false,
                    'verified' => false,
                    'signatures' => [],
                ],
            ]);

        $this->actingAs($viewer)
            ->get(sprintf('/documents/%d/download', $document->id))
            ->assertOk()
            ->assertDownload('resident-permit-v2.pdf');

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $viewer->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentDownloaded->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $secondRevision->id,
        ]);

        $this->actingAs($viewer)
            ->getJson(sprintf('/documents/%d/verification-bundle', $document->id))
            ->assertOk()
            ->assertJson([
                'message' => 'Document verification bundle loaded successfully.',
                'bundle' => [
                    'version' => 1,
                    'document' => [
                        'id' => $document->id,
                        'title' => 'Resident permit',
                    ],
                    'revision' => [
                        'id' => $secondRevision->id,
                        'number' => 2,
                        'sha256' => $secondRevision->sha256,
                        'signatureStatus' => 'unsigned',
                    ],
                    'signatures' => [],
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $viewer->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentVerificationBundleDownloaded->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $secondRevision->id,
        ]);

        $this->actingAs($viewer)
            ->get(sprintf('/documents/%d/revisions/%d/download', $document->id, $firstRevision->id))
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_document_action',
                    'action' => 'history.read',
                    'currentRole' => UserRole::NonCoordinator->value,
                ],
            ]);

        $this->actingAs($viewer)
            ->getJson(sprintf('/documents/%d/revisions/%d/verification-bundle', $document->id, $firstRevision->id))
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_document_action',
                    'action' => 'history.read',
                    'currentRole' => UserRole::NonCoordinator->value,
                ],
            ]);
    }

    public function test_coordinator_can_read_owned_old_revision_history_but_not_foreign_old_history(): void
    {
        Storage::fake('local');

        $owner = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $coordinator = $this->createEnrolledUser(UserRole::Coordinator);

        $document = Document::factory()->create([
            'title' => 'Case archive',
            'owner_user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
        ]);

        $firstStoragePath = sprintf('documents/%d/revisions/1/case-archive-v1.pdf', $document->id);
        $secondStoragePath = sprintf('documents/%d/revisions/2/case-archive-v2.pdf', $document->id);

        Storage::disk('local')->put($firstStoragePath, 'owned-old-revision');
        Storage::disk('local')->put($secondStoragePath, 'current-revision');

        $firstRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => null,
            'created_by_user_id' => $coordinator->id,
            'revision_number' => 1,
            'storage_disk' => 'local',
            'storage_path' => $firstStoragePath,
            'original_file_name' => 'case-archive-v1.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen('owned-old-revision'),
            'sha256' => hash('sha256', 'owned-old-revision'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'initial_upload',
            ],
        ]);

        $secondRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $firstRevision->id,
            'created_by_user_id' => $owner->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => $secondStoragePath,
            'original_file_name' => 'case-archive-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen('current-revision'),
            'sha256' => hash('sha256', 'current-revision'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $this->actingAs($coordinator)
            ->getJson(sprintf('/documents/%d', $document->id))
            ->assertOk()
            ->assertJsonCount(2, 'document.revisions')
            ->assertJsonPath('document.capabilities.canDownloadCurrent', true)
            ->assertJsonPath('document.capabilities.canReadCurrentVerificationBundle', true)
            ->assertJsonPath('document.capabilities.canSignCurrent', true)
            ->assertJsonPath('document.capabilities.canUploadRevision', true)
            ->assertJsonPath('document.capabilities.canDeleteDocument', false)
            ->assertJsonPath('document.revisions.0.id', $secondRevision->id)
            ->assertJsonPath('document.revisions.1.id', $firstRevision->id)
            ->assertJsonPath('document.revisions.0.capabilities.canDownload', true)
            ->assertJsonPath('document.revisions.0.capabilities.canReadVerificationBundle', true)
            ->assertJsonPath('document.revisions.0.capabilities.canSign', true)
            ->assertJsonPath('document.revisions.1.capabilities.canDownload', true)
            ->assertJsonPath('document.revisions.1.capabilities.canReadVerificationBundle', true)
            ->assertJsonPath('document.revisions.1.capabilities.canSign', true);

        $this->actingAs($coordinator)
            ->get(sprintf('/documents/%d/revisions/%d/download', $document->id, $firstRevision->id))
            ->assertOk()
            ->assertDownload('case-archive-v1.pdf');

        $this->actingAs($coordinator)
            ->getJson(sprintf('/documents/%d/revisions/%d/verification-bundle', $document->id, $firstRevision->id))
            ->assertOk()
            ->assertJsonPath('bundle.revision.id', $firstRevision->id);

        $foreignOldRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $secondRevision->id,
            'created_by_user_id' => $owner->id,
            'revision_number' => 3,
            'storage_disk' => 'local',
            'storage_path' => sprintf('documents/%d/revisions/3/case-archive-v3.pdf', $document->id),
            'original_file_name' => 'case-archive-v3.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen('foreign-old-revision'),
            'sha256' => hash('sha256', 'foreign-old-revision'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $this->actingAs($coordinator)
            ->getJson(sprintf('/documents/%d/revisions/%d/verification-bundle', $document->id, $foreignOldRevision->id))
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_document_action',
                    'action' => 'history.read',
                    'currentRole' => UserRole::Coordinator->value,
                ],
            ]);
    }

    private function createEnrolledUser(UserRole $role): User
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
        ]);

        if ($role === UserRole::Coordinator) {
            $user->webauthnCredentials()->create([
                'credential_id' => 'credential-'.$user->id,
                'public_key' => 'test-public-key',
                'public_key_algorithm' => -7,
                'name' => 'Test passkey',
                'attestation_object' => 'test-attestation-object',
                'client_data_json' => 'test-client-data-json',
            ]);
        }

        return $user;
    }
}
