<?php

namespace Tests\Feature\Api\Documents;

use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Documents\DocumentSignaturePolicyService;
use App\Services\Documents\DocumentSignatureRequirementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentSignaturePolicyApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_configure_a_revision_signature_policy(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-policy');
        $coordinator = $this->createSigningUser(UserRole::Coordinator, 'coordinator-policy');
        [$document, $revision] = $this->createDocumentWithRevision($admin);

        $this->actingAs($admin)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => true,
                'requirements' => [
                    [
                        'type' => 'role',
                        'role' => UserRole::Admin->value,
                    ],
                    [
                        'type' => 'user',
                        'userId' => $coordinator->id,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('revision.signaturePolicy.version', 2)
            ->assertJsonPath('revision.signaturePolicy.signatureOrderEnforced', true)
            ->assertJsonPath('revision.signaturePolicy.requirements.0.signerRole', UserRole::Admin->value)
            ->assertJsonPath('revision.signaturePolicy.requirements.1.signerUser.id', $coordinator->id);

        $this->assertDatabaseHas('document_revisions', [
            'id' => $revision->id,
            'signature_order_enforced' => true,
        ]);
        $this->assertDatabaseHas('document_signature_requirements', [
            'document_revision_id' => $revision->id,
            'sequence' => 1,
            'signer_role' => UserRole::Admin->value,
        ]);
        $this->assertDatabaseHas('document_signature_requirements', [
            'document_revision_id' => $revision->id,
            'sequence' => 2,
            'signer_user_id' => $coordinator->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $admin->id,
            'document_id' => $document->id,
            'revision_id' => $revision->id,
            'event_type' => 'document.signature_policy.updated',
        ]);
    }

    public function test_only_admins_can_load_options_or_update_policies(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-owner');
        $coordinator = $this->createSigningUser(UserRole::Coordinator, 'coordinator-denied');
        [$document, $revision] = $this->createDocumentWithRevision($admin);

        $this->actingAs($coordinator)
            ->getJson('/documents/signature-policy/signer-options')
            ->assertForbidden();

        $this->actingAs($coordinator)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => false,
                'requirements' => [],
            ])
            ->assertForbidden();
    }

    public function test_signer_options_only_include_active_privileged_accounts_with_passkeys(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-options');
        $coordinator = $this->createSigningUser(UserRole::Coordinator, 'coordinator-options');
        $withoutPasskey = User::factory()->create(['role' => UserRole::Coordinator->value]);
        $volunteer = $this->createSigningUser(UserRole::Volunteer, 'volunteer-options');

        $response = $this->actingAs($admin)
            ->getJson('/documents/signature-policy/signer-options')
            ->assertOk()
            ->assertJsonPath('roles.0.value', UserRole::Admin->value)
            ->assertJsonPath('roles.1.value', UserRole::Coordinator->value);

        $optionIds = collect($response->json('users'))->pluck('id')->all();

        $this->assertContains($admin->id, $optionIds);
        $this->assertContains($coordinator->id, $optionIds);
        $this->assertNotContains($withoutPasskey->id, $optionIds);
        $this->assertNotContains($volunteer->id, $optionIds);
    }

    public function test_stale_policy_updates_are_rejected(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-stale');
        [$document, $revision] = $this->createDocumentWithRevision($admin);
        $revision->forceFill(['signature_policy_version' => 2])->save();

        $this->actingAs($admin)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => false,
                'requirements' => [],
            ])
            ->assertStatus(409);
    }

    public function test_policy_requires_distinct_eligible_signers_and_rejects_cross_document_revisions(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-feasibility');
        [$document, $revision] = $this->createDocumentWithRevision($admin);
        [$otherDocument, $otherRevision] = $this->createDocumentWithRevision($admin);

        $this->actingAs($admin)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => false,
                'requirements' => [
                    ['type' => 'user', 'userId' => $admin->id],
                    ['type' => 'user', 'userId' => $admin->id],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirements.1.userId');

        $this->actingAs($admin)
            ->putJson("/documents/{$otherDocument->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => false,
                'requirements' => [],
            ])
            ->assertNotFound();

        $this->assertNotSame($revision->id, $otherRevision->id);
    }

    public function test_new_revision_clones_policy_structure_without_fulfillment(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-clone');
        [$document, $source] = $this->createDocumentWithRevision($admin);
        $signature = $this->createSignature($source, $admin);
        $source->signatureRequirements()->create([
            'sequence' => 1,
            'signer_user_id' => $admin->id,
            'fulfilled_by_signature_id' => $signature->id,
            'fulfilled_at' => $signature->signed_at,
        ]);
        $source->forceFill(['signature_order_enforced' => true])->save();

        $target = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $source->id,
            'created_by_user_id' => $admin->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$document->id}/revisions/2/example.pdf",
            'original_file_name' => 'example.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'revision-two'),
            'signature_status' => 'unsigned',
        ]);

        app(DocumentSignaturePolicyService::class)->clonePolicy($source, $target);

        $cloned = $target->signatureRequirements()->sole();
        $this->assertTrue($target->fresh()->signature_order_enforced);
        $this->assertSame($admin->id, $cloned->signer_user_id);
        $this->assertNull($cloned->fulfilled_by_signature_id);
        $this->assertNull($cloned->fulfilled_at);
        $this->assertDatabaseHas('document_signatures', ['id' => $signature->id]);
    }

    public function test_unordered_policy_prioritizes_explicit_user_and_ordered_policy_uses_first_step(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-priority');
        [$document, $unordered] = $this->createDocumentWithRevision($admin);
        $roleRequirement = $unordered->signatureRequirements()->create([
            'sequence' => 1,
            'signer_role' => UserRole::Admin->value,
        ]);
        $userRequirement = $unordered->signatureRequirements()->create([
            'sequence' => 2,
            'signer_user_id' => $admin->id,
        ]);
        $signature = $this->createSignature($unordered, $admin);

        $service = app(DocumentSignatureRequirementService::class);
        $service->fulfillForSignature($unordered, $admin, $signature);

        $this->assertNull($roleRequirement->fresh()->fulfilled_by_signature_id);
        $this->assertSame($signature->id, $userRequirement->fresh()->fulfilled_by_signature_id);

        $ordered = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $unordered->id,
            'created_by_user_id' => $admin->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$document->id}/revisions/2/ordered.pdf",
            'original_file_name' => 'ordered.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'ordered'),
            'signature_status' => 'unsigned',
            'signature_order_enforced' => true,
        ]);
        $orderedRole = $ordered->signatureRequirements()->create([
            'sequence' => 1,
            'signer_role' => UserRole::Admin->value,
        ]);
        $ordered->signatureRequirements()->create([
            'sequence' => 2,
            'signer_user_id' => $admin->id,
        ]);
        $orderedSignature = $this->createSignature($ordered, $admin);

        $service->fulfillForSignature($ordered, $admin, $orderedSignature);

        $this->assertSame($orderedSignature->id, $orderedRole->fresh()->fulfilled_by_signature_id);
    }

    public function test_policy_edits_recalculate_status_and_allow_clearing_pending_steps(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-status');
        $coordinator = $this->createSigningUser(UserRole::Coordinator, 'coordinator-status');
        [$document, $revision] = $this->createDocumentWithRevision($admin);
        $this->createSignature($revision, $admin);
        $revision->forceFill(['signature_status' => 'signed'])->save();

        $this->actingAs($admin)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => false,
                'requirements' => [
                    ['type' => 'user', 'userId' => $coordinator->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('revision.signatureStatus', 'partially_signed');

        $this->actingAs($admin)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 2,
                'signatureOrderEnforced' => false,
                'requirements' => [],
            ])
            ->assertOk()
            ->assertJsonPath('revision.signatureStatus', 'signed');
    }

    public function test_fulfilled_requirements_cannot_be_removed_or_reassigned(): void
    {
        $admin = $this->createSigningUser(UserRole::Admin, 'admin-immutable');
        [$document, $revision] = $this->createDocumentWithRevision($admin);
        $signature = $this->createSignature($revision, $admin);
        $requirement = $revision->signatureRequirements()->create([
            'sequence' => 1,
            'signer_role' => UserRole::Admin->value,
            'fulfilled_by_signature_id' => $signature->id,
            'fulfilled_at' => $signature->signed_at,
        ]);

        $this->actingAs($admin)
            ->putJson("/documents/{$document->id}/revisions/{$revision->id}/signature-policy", [
                'expectedVersion' => 1,
                'signatureOrderEnforced' => false,
                'requirements' => [
                    [
                        'id' => $requirement->id,
                        'type' => 'role',
                        'role' => UserRole::Coordinator->value,
                    ],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('requirements.0');

        $this->assertDatabaseHas('document_signature_requirements', [
            'id' => $requirement->id,
            'signer_role' => UserRole::Admin->value,
            'fulfilled_by_signature_id' => $signature->id,
        ]);
    }

    private function createSigningUser(UserRole $role, string $credentialId): User
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'totp-secret',
        ]);

        WebauthnCredential::query()->create([
            'user_id' => $user->id,
            'credential_id' => $credentialId,
            'public_key' => 'public-key',
            'public_key_algorithm' => -7,
            'name' => "{$role->value} key",
            'sign_count' => 0,
            'transports' => ['usb'],
            'attestation_object' => 'attestation',
            'client_data_json' => 'client-data',
        ]);

        return $user;
    }

    /**
     * @return array{Document, DocumentRevision}
     */
    private function createDocumentWithRevision(User $owner): array
    {
        $document = Document::factory()->create([
            'owner_user_id' => $owner->id,
            'uploaded_by_user_id' => $owner->id,
        ]);
        $revision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => null,
            'created_by_user_id' => $owner->id,
            'revision_number' => 1,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$document->id}/revisions/1/example.pdf",
            'original_file_name' => 'example.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'sha256' => hash('sha256', 'payload'),
            'signature_status' => 'unsigned',
            'diff_metadata' => ['kind' => 'initial_upload'],
        ]);

        $document->forceFill(['current_revision_id' => $revision->id])->save();

        return [$document, $revision];
    }

    private function createSignature(DocumentRevision $revision, User $signer): DocumentSignature
    {
        return $revision->signatures()->create([
            'signed_by_user_id' => $signer->id,
            'signature_type' => 'passkey',
            'verification_status' => 'verified',
            'signed_at' => CarbonImmutable::now('UTC'),
            'signature_hash' => $revision->sha256,
        ]);
    }
}
