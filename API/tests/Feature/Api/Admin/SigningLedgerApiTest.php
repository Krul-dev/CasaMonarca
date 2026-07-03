<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SigningLedgerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_signing_ledger_grouped_by_signer_and_document(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $coordinator = User::factory()->create([
            'email' => 'coordinator@casamonarca.local',
            'name' => 'Coordinator Local',
            'role' => UserRole::Coordinator->value,
        ]);
        $document = Document::factory()->create([
            'title' => 'Signed dossier',
            'owner_user_id' => $coordinator->id,
            'uploaded_by_user_id' => $coordinator->id,
        ]);
        $revision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'created_by_user_id' => $coordinator->id,
            'revision_number' => 1,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/1/file.pdf',
            'original_file_name' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1200,
            'sha256' => str_repeat('a', 64),
            'signature_status' => 'signed',
        ]);
        $document->forceFill([
            'current_revision_id' => $revision->id,
        ])->save();
        $unsignedRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $revision->id,
            'created_by_user_id' => $coordinator->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/file-v2.pdf',
            'original_file_name' => 'file-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1300,
            'sha256' => str_repeat('b', 64),
            'signature_status' => 'unsigned',
        ]);
        $signature = $revision->signatures()->create([
            'signed_by_user_id' => $coordinator->id,
            'signature_type' => 'passkey',
            'verification_status' => 'verified',
            'signed_at' => now(),
            'signature_hash' => $revision->sha256,
            'metadata' => [
                'credentialId' => 'credential-coordinator-1',
                'credentialName' => 'Coordinator security key',
                'publicKeyFingerprintSha256' => 'fingerprint-coordinator-1',
                'validity' => [
                    'expiresAt' => '2027-04-25T00:00:00+00:00',
                ],
            ],
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/signing-ledger')
            ->assertOk()
            ->assertJson([
                'message' => 'Signing ledger loaded successfully.',
            ])
            ->assertJsonPath('signers.0.email', $admin->email)
            ->assertJsonPath('signers.0.signatureCount', 0)
            ->assertJsonPath('signers.1.email', $coordinator->email)
            ->assertJsonPath('signers.1.signatureCount', 1)
            ->assertJsonPath('signers.1.documents.0.title', 'Signed dossier')
            ->assertJsonPath('signers.1.documents.0.revisions.0.id', $revision->id)
            ->assertJsonPath('signers.1.documents.0.revisions.0.signatures.0.id', $signature->id)
            ->assertJsonPath('signers.1.documents.0.revisions.0.signatures.0.credential.id', 'credential-coordinator-1')
            ->assertJsonPath('signers.1.documents.0.revisions.0.signatures.0.credential.publicKeyFingerprintSha256', 'fingerprint-coordinator-1')
            ->assertJsonPath('signers.1.documents.0.revisions.0.signatures.0.expiresAt', '2027-04-25T00:00:00+00:00')
            ->assertJsonPath('documents.0.title', 'Signed dossier')
            ->assertJsonPath('documents.0.revisions.0.id', $unsignedRevision->id)
            ->assertJsonPath('documents.0.revisions.0.signatureStatus', 'unsigned')
            ->assertJsonPath('documents.0.revisions.0.signatures', [])
            ->assertJsonPath('documents.0.revisions.1.id', $revision->id)
            ->assertJsonPath('documents.0.revisions.1.signatures.0.signedBy.email', $coordinator->email);
    }

    public function test_non_admin_cannot_view_signing_ledger(): void
    {
        $coordinator = User::factory()->create([
            'role' => UserRole::Coordinator->value,
        ]);

        $this->actingAs($coordinator)
            ->getJson('/admin/signing-ledger')
            ->assertForbidden();
    }
}
