<?php

namespace Tests\Feature\Api\Documents;

use App\Enums\AuditEventOutcome;
use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignature;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\WebauthnAssertionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentSensitiveOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordinator_can_request_document_sign_challenge(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);

        $response = $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/sign/options', $document->id))
            ->assertOk()
            ->assertJson([
                'message' => 'Document signature challenge created.',
            ])
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rpId',
                    'allowCredentials',
                ],
                'challengeIntent' => [
                    'id',
                    'purpose',
                    'status',
                    'expiresAt',
                ],
                'signingTarget' => [
                    'documentId',
                    'revisionId',
                    'revisionNumber',
                    'documentHash',
                    'expiresAt',
                ],
            ]);

        $response->assertSessionHas('documents.sign.webauthn.intent.documentId', $document->id);
        $response->assertSessionHas('documents.sign.webauthn.intent.revisionId', $document->currentRevision->id);
        $response->assertSessionHas('documents.sign.webauthn.intent.revisionSha256', $document->currentRevision->sha256);
        $response->assertSessionHas('documents.sign.webauthn.challenge_intent_id');
        $this->assertDatabaseHas('security_challenge_intents', [
            'actor_user_id' => $user->id,
            'purpose' => 'document.sign',
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'target_type' => 'document_revision',
            'target_id' => $document->currentRevision->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentSignatureChallengeStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $document->currentRevision->id,
        ]);
    }

    public function test_non_coordinator_cannot_request_document_sign_challenge(): void
    {
        $user = $this->createUserWithCredential(UserRole::NonCoordinator);
        $document = $this->createDocumentWithRevision($user);

        $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/sign/options', $document->id))
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_role',
                    'requiredRoles' => [
                        UserRole::Admin->value,
                        UserRole::Coordinator->value,
                    ],
                    'currentRole' => UserRole::NonCoordinator->value,
                ],
            ]);
    }

    public function test_user_can_cancel_pending_document_sign_challenge(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);

        $response = $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/sign/options', $document->id))
            ->assertOk();

        $intentId = $response->json('challengeIntent.id');
        $this->assertIsString($intentId);

        $this->actingAs($user)
            ->postJson(sprintf('/security-challenges/%s/cancel', $intentId))
            ->assertOk()
            ->assertJson([
                'message' => 'Challenge intent cancelled.',
                'challengeIntent' => [
                    'id' => $intentId,
                    'status' => SecurityChallengeIntent::STATUS_CANCELLED,
                ],
            ]);

        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $intentId,
            'status' => SecurityChallengeIntent::STATUS_CANCELLED,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::SecurityChallengeCancelled->value,
            'outcome' => AuditEventOutcome::Failure->value,
            'revision_id' => $document->currentRevision->id,
        ]);
    }

    public function test_expire_security_challenges_command_marks_abandoned_intents(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $intent = SecurityChallengeIntent::query()->create([
            'purpose' => 'document.sign',
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'actor_user_id' => $user->id,
            'target_type' => 'document_revision',
            'target_id' => $document->currentRevision->id,
            'challenge_hash' => hash('sha256', 'expired-challenge'),
            'payload' => [
                'documentId' => $document->id,
                'revisionId' => $document->currentRevision->id,
            ],
            'origin' => 'http://localhost',
            'rp_id' => 'localhost',
            'expires_at' => now('UTC')->subMinute(),
        ]);

        $this->artisan('security-challenges:expire')
            ->expectsOutput('Expired 1 pending security challenge intent(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $intent->getKey(),
            'status' => SecurityChallengeIntent::STATUS_EXPIRED,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::SecurityChallengeExpired->value,
            'outcome' => AuditEventOutcome::Failure->value,
            'revision_id' => $document->currentRevision->id,
        ]);
    }

    public function test_coordinator_can_request_sign_challenge_for_non_current_revision(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $firstRevision = $document->currentRevision()->firstOrFail();
        $secondRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $firstRevision->id,
            'created_by_user_id' => $user->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/example-v2.pdf',
            'original_file_name' => 'example-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'payload-v2'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $response = $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/revisions/%d/sign/options', $document->id, $firstRevision->id))
            ->assertOk()
            ->assertJson([
                'message' => 'Document signature challenge created.',
                'signingTarget' => [
                    'documentId' => $document->id,
                    'revisionId' => $firstRevision->id,
                    'revisionNumber' => 1,
                    'documentHash' => $firstRevision->sha256,
                ],
            ]);

        $response->assertSessionHas('documents.sign.webauthn.intent.revisionId', $firstRevision->id);
    }

    public function test_coordinator_cannot_request_sign_challenge_for_foreign_old_revision(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $owner = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $document = $this->createDocumentWithRevision($owner);
        $firstRevision = $document->currentRevision()->firstOrFail();
        $secondRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $firstRevision->id,
            'created_by_user_id' => $owner->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/example-v2.pdf',
            'original_file_name' => 'example-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'payload-v2'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/revisions/%d/sign/options', $document->id, $firstRevision->id))
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_document_action',
                    'action' => 'document.sign',
                    'currentRole' => UserRole::Coordinator->value,
                ],
            ]);
    }

    public function test_coordinator_can_sign_current_revision_after_passkey_step_up(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $signSession = $this->signSession($user, $document);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(7);
        });

        $this->actingAs($user)
            ->withSession($signSession)
            ->postJson(
                sprintf('/documents/%d/sign/verify', $document->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertOk()
            ->assertJsonStructure([
                'signature' => [
                    'id',
                    'signedAt',
                    'expiresAt',
                ],
                'verification' => [
                    'signatures' => [
                        [
                            'id',
                            'signedAt',
                            'expiresAt',
                        ],
                    ],
                ],
            ])
            ->assertJson([
                'message' => 'Document signed successfully.',
                'verification' => [
                    'documentId' => $document->id,
                    'currentRevisionNumber' => 1,
                    'signatureStatus' => 'signed',
                    'hasSignatures' => true,
                    'verified' => true,
                ],
            ]);

        $revision = $document->currentRevision()->firstOrFail();

        $this->assertDatabaseHas('document_signatures', [
            'document_revision_id' => $revision->id,
            'signed_by_user_id' => $user->id,
            'signature_type' => 'passkey',
            'verification_status' => 'verified',
            'signature_hash' => $revision->sha256,
        ]);

        $this->assertSame(7, (int) $user->webauthnCredentials()->sole()->sign_count);

        $signature = DocumentSignature::query()->sole();

        $this->assertEquals($signSession['documents.sign.webauthn.intent'], $signature->metadata['intent']);
        $this->assertSame(
            '{"documentId":'.$document->id.',"expiresAt":"'.$signSession['documents.sign.webauthn.intent']['expiresAt'].'","issuedAt":"'.$signSession['documents.sign.webauthn.intent']['issuedAt'].'","nonce":"nonce-sign","origin":"http://localhost","purpose":"document-sign","revisionId":'.$revision->id.',"revisionNumber":1,"revisionSha256":"'.$revision->sha256.'","rpId":"localhost","userId":'.$user->id.',"version":1}',
            $signature->metadata['canonicalIntent'],
        );
        $this->assertSame('server-policy', data_get($signature->metadata, 'validity.source'));
        $this->assertSame(config('documents.signature_validity_days'), data_get($signature->metadata, 'validity.days'));
        $this->assertTrue(is_string(data_get($signature->metadata, 'validity.expiresAt')));
        $this->assertSame('credential-coordinator', data_get($signature->metadata, 'assertion.id'));
        $this->assertSame('client-data', data_get($signature->metadata, 'assertion.response.clientDataJSON'));
        $this->assertSame('authenticator-data', data_get($signature->metadata, 'assertion.response.authenticatorData'));
        $this->assertSame('signature', data_get($signature->metadata, 'assertion.response.signature'));
        $this->assertSame(data_get($signature->metadata, 'challenge'), $this->expectedChallenge($signSession));
        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $signSession['documents.sign.webauthn.challenge_intent_id'],
            'status' => SecurityChallengeIntent::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentSigned->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $revision->id,
        ]);
    }

    public function test_coordinator_can_sign_current_revision_created_by_someone_else(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $owner = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $document = $this->createDocumentWithRevision($owner);
        $signSession = $this->signSession($user, $document);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(17);
        });

        $this->actingAs($user)
            ->withSession($signSession)
            ->postJson(
                sprintf('/documents/%d/sign/verify', $document->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Document signed successfully.',
                'verification' => [
                    'documentId' => $document->id,
                    'currentRevisionNumber' => 1,
                    'signatureStatus' => 'signed',
                ],
            ]);

        $this->assertDatabaseHas('document_signatures', [
            'document_revision_id' => $document->currentRevision->id,
            'signed_by_user_id' => $user->id,
            'signature_hash' => $document->currentRevision->sha256,
        ]);
    }

    public function test_coordinator_can_sign_an_older_revision_after_passkey_step_up(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $firstRevision = $document->currentRevision()->firstOrFail();
        $secondRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $firstRevision->id,
            'created_by_user_id' => $user->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/example-v2.pdf',
            'original_file_name' => 'example-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'payload-v2'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(8);
        });

        $signSession = $this->signSessionForRevision($user, $document, $firstRevision);

        $this->actingAs($user)
            ->withSession($signSession)
            ->postJson(
                sprintf('/documents/%d/revisions/%d/sign/verify', $document->id, $firstRevision->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Document signed successfully.',
                'verification' => [
                    'documentId' => $document->id,
                    'currentRevisionId' => $firstRevision->id,
                    'currentRevisionNumber' => 1,
                    'signatureStatus' => 'signed',
                ],
            ]);

        $this->assertDatabaseHas('document_signatures', [
            'document_revision_id' => $firstRevision->id,
            'signed_by_user_id' => $user->id,
            'signature_hash' => $firstRevision->sha256,
        ]);
        $this->assertDatabaseHas('documents', [
            'id' => $document->id,
            'current_revision_id' => $secondRevision->id,
        ]);
        $this->assertDatabaseHas('document_revisions', [
            'id' => $firstRevision->id,
            'signature_status' => 'signed',
        ]);
        $this->assertDatabaseHas('document_revisions', [
            'id' => $secondRevision->id,
            'signature_status' => 'unsigned',
        ]);
    }

    public function test_coordinator_cannot_sign_a_foreign_older_revision_even_with_step_up_session(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $owner = User::factory()->create([
            'role' => UserRole::Admin->value,
        ]);
        $document = $this->createDocumentWithRevision($owner);
        $firstRevision = $document->currentRevision()->firstOrFail();
        $secondRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $firstRevision->id,
            'created_by_user_id' => $owner->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/example-v2.pdf',
            'original_file_name' => 'example-v2.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', 'payload-v2'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $secondRevision->id,
        ])->save();

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldNotReceive('verifyAssertionPayload');
        });

        $this->actingAs($user)
            ->withSession($this->signSessionForRevision($user, $document, $firstRevision))
            ->postJson(
                sprintf('/documents/%d/revisions/%d/sign/verify', $document->id, $firstRevision->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertForbidden()
            ->assertJson([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_document_action',
                    'action' => 'document.sign',
                    'currentRole' => UserRole::Coordinator->value,
                ],
            ]);

        $this->assertDatabaseCount('document_signatures', 0);
    }

    public function test_document_sign_verification_rejects_revision_drift_after_challenge_creation(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $signSession = $this->signSession($user, $document);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldNotReceive('verifyAssertionPayload');
        });

        $newRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $document->currentRevision->id,
            'created_by_user_id' => $user->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/example.pdf',
            'original_file_name' => 'example.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'payload-v2'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $newRevision->id,
        ])->save();

        $this->actingAs($user)
            ->withSession($signSession)
            ->postJson(
                sprintf('/documents/%d/sign/verify', $document->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertConflict()
            ->assertJson([
                'message' => 'Document signature challenge no longer matches the current revision. Reload the document and sign again.',
            ]);

        $this->assertDatabaseCount('document_signatures', 0);
    }

    public function test_coordinator_can_request_document_revision_update_challenge(): void
    {
        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);

        $candidateHash = hash('sha256', 'payload-v2');

        $response = $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/revisions/options', $document->id), [
                'originalFileName' => 'example-v2.pdf',
                'sizeBytes' => strlen('payload-v2'),
                'sha256' => $candidateHash,
            ])
            ->assertOk()
            ->assertJson([
                'message' => 'Document revision update challenge created.',
                'revisionTarget' => [
                    'documentId' => $document->id,
                    'parentRevisionId' => $document->currentRevision->id,
                    'parentRevisionNumber' => 1,
                    'parentRevisionHash' => $document->currentRevision->sha256,
                    'candidateHash' => $candidateHash,
                    'candidateOriginalFileName' => 'example-v2.pdf',
                ],
            ]);

        $response->assertSessionHas('documents.revisions.update.webauthn.intent.documentId', $document->id);
        $response->assertSessionHas('documents.revisions.update.webauthn.intent.revisionId', $document->currentRevision->id);
        $response->assertSessionHas('documents.revisions.update.webauthn.intent.candidateSha256', $candidateHash);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentRevisionChallengeStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $document->currentRevision->id,
        ]);
    }

    public function test_admin_can_request_document_delete_challenge(): void
    {
        $user = $this->createUserWithCredential(UserRole::Admin);
        $document = $this->createDocumentWithRevision($user);

        $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/delete/options', $document->id))
            ->assertOk()
            ->assertJson([
                'message' => 'Document deletion challenge created.',
            ])
            ->assertJsonStructure([
                'options' => [
                    'challenge',
                    'rpId',
                    'allowCredentials',
                ],
                'challengeIntent' => [
                    'id',
                    'purpose',
                    'status',
                    'expiresAt',
                ],
            ]);
        $this->assertDatabaseHas('security_challenge_intents', [
            'actor_user_id' => $user->id,
            'purpose' => 'document.delete',
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'target_type' => 'document',
            'target_id' => $document->id,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentDeleteChallengeStarted->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
    }

    public function test_admin_can_cancel_pending_document_delete_challenge(): void
    {
        $user = $this->createUserWithCredential(UserRole::Admin);
        $document = $this->createDocumentWithRevision($user);

        $response = $this->actingAs($user)
            ->postJson(sprintf('/documents/%d/delete/options', $document->id))
            ->assertOk();

        $intentId = $response->json('challengeIntent.id');
        $this->assertIsString($intentId);

        $this->actingAs($user)
            ->postJson(sprintf('/security-challenges/%s/cancel', $intentId))
            ->assertOk()
            ->assertJson([
                'message' => 'Challenge intent cancelled.',
                'challengeIntent' => [
                    'id' => $intentId,
                    'status' => SecurityChallengeIntent::STATUS_CANCELLED,
                ],
            ]);

        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::SecurityChallengeCancelled->value,
            'outcome' => AuditEventOutcome::Failure->value,
        ]);
    }

    public function test_coordinator_can_upload_new_revision_after_passkey_step_up(): void
    {
        Storage::fake('local');

        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $payload = 'payload-v2';
        $updateSession = $this->revisionUpdateSession($user, $document, 'example-v2.pdf', $payload);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(13);
        });

        $this->actingAs($user)
            ->withSession($updateSession)
            ->post(
                sprintf('/documents/%d/revisions/verify', $document->id),
                array_merge($this->assertionPayload('credential-coordinator'), [
                    'file' => UploadedFile::fake()->createWithContent('example-v2.pdf', $payload),
                ]),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Document revision uploaded successfully.',
                'document' => [
                    'id' => $document->id,
                    'currentRevision' => [
                        'revisionNumber' => 2,
                        'originalFileName' => 'example-v2.pdf',
                        'sha256' => hash('sha256', $payload),
                        'signatureStatus' => 'unsigned',
                    ],
                ],
            ]);

        $document->refresh();
        $newRevision = $document->currentRevision()->firstOrFail();

        $this->assertSame(2, (int) $newRevision->revision_number);
        $this->assertSame($document->revisions()->where('revision_number', 1)->value('id'), $newRevision->parent_revision_id);
        $this->assertSame('revision_update', data_get($newRevision->diff_metadata, 'kind'));
        $this->assertSame('credential-coordinator', data_get($newRevision->diff_metadata, 'credentialId'));
        $this->assertSame($this->expectedChallengeForIntent($updateSession['documents.revisions.update.webauthn.intent']), data_get($newRevision->diff_metadata, 'challenge'));
        $this->assertSame(13, (int) $user->webauthnCredentials()->sole()->sign_count);
        Storage::disk('local')->assertExists($newRevision->storage_path);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentRevisionCreated->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $newRevision->id,
        ]);
    }

    public function test_document_revision_update_rejects_revision_drift_after_challenge_creation(): void
    {
        Storage::fake('local');

        $user = $this->createUserWithCredential(UserRole::Coordinator);
        $document = $this->createDocumentWithRevision($user);
        $payload = 'payload-v2';
        $updateSession = $this->revisionUpdateSession($user, $document, 'example-v2.pdf', $payload);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldNotReceive('verifyAssertionPayload');
        });

        $newRevision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => $document->currentRevision->id,
            'created_by_user_id' => $user->id,
            'revision_number' => 2,
            'storage_disk' => 'local',
            'storage_path' => 'documents/1/revisions/2/drift.pdf',
            'original_file_name' => 'drift.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 8,
            'sha256' => hash('sha256', 'drift'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'revision_update',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $newRevision->id,
        ])->save();

        $this->actingAs($user)
            ->withSession($updateSession)
            ->post(
                sprintf('/documents/%d/revisions/verify', $document->id),
                array_merge($this->assertionPayload('credential-coordinator'), [
                    'file' => UploadedFile::fake()->createWithContent('example-v2.pdf', $payload),
                ]),
            )
            ->assertConflict()
            ->assertJson([
                'message' => 'Document revision update challenge no longer matches the current revision. Reload the document and try again.',
            ]);

        $this->assertSame(2, $document->revisions()->count());
    }

    public function test_admin_can_delete_document_after_passkey_step_up(): void
    {
        Storage::fake('local');

        $user = $this->createUserWithCredential(UserRole::Admin);
        $document = $this->createDocumentWithRevision($user, 'documents/100/revisions/1/sample.pdf');

        Storage::disk('local')->put('documents/100/revisions/1/sample.pdf', 'payload');

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(9);
        });
        $deleteSession = $this->deleteSession($user->id, $document->id);

        $this->actingAs($user)
            ->withSession($deleteSession)
            ->postJson(
                sprintf('/documents/%d/delete/verify', $document->id),
                $this->assertionPayload('credential-admin'),
            )
            ->assertOk()
            ->assertJson([
                'message' => 'Document deleted permanently.',
                'tombstone' => [
                    'originalDocumentId' => $document->id,
                    'revisionCount' => 1,
                ],
            ]);

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);
        $this->assertDatabaseHas('document_tombstones', [
            'original_document_id' => $document->id,
            'deleted_by_user_id' => $user->id,
            'revision_count' => 1,
        ]);
        Storage::disk('local')->assertMissing('documents/100/revisions/1/sample.pdf');
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $user->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentDeleted->value,
            'outcome' => AuditEventOutcome::Success->value,
        ]);
        $this->assertDatabaseHas('security_challenge_intents', [
            'id' => $deleteSession['documents.delete.webauthn.challenge_intent_id'],
            'status' => SecurityChallengeIntent::STATUS_SUCCEEDED,
        ]);
    }

    public function test_non_coordinator_can_fetch_verification_bundle_for_signed_revision(): void
    {
        $signer = $this->createUserWithCredential(UserRole::Coordinator);
        $viewer = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'totp-secret',
        ]);
        $document = $this->createDocumentWithRevision($signer);
        $signSession = $this->signSession($signer, $document);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(11);
        });

        $this->actingAs($signer)
            ->withSession($signSession)
            ->postJson(
                sprintf('/documents/%d/sign/verify', $document->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertOk();

        $this->actingAs($viewer)
            ->getJson(sprintf('/documents/%d/verification-bundle', $document->id))
            ->assertOk()
            ->assertJson([
                'message' => 'Document verification bundle loaded successfully.',
                'bundle' => [
                    'version' => 1,
                    'document' => [
                        'id' => $document->id,
                        'title' => 'Protected document',
                    ],
                    'revision' => [
                        'id' => $document->currentRevision->id,
                        'number' => 1,
                        'sha256' => $document->currentRevision->sha256,
                        'signatureStatus' => 'signed',
                    ],
                ],
            ])
            ->assertJsonPath('bundle.signatures.0.intent.documentId', $document->id)
            ->assertJsonPath('bundle.signatures.0.intent.revisionId', $document->currentRevision->id)
            ->assertJsonPath('bundle.signatures.0.assertion.id', 'credential-coordinator')
            ->assertJsonPath('bundle.signatures.0.assertion.response.clientDataJSON', 'client-data')
            ->assertJsonPath('bundle.signatures.0.credential.id', 'credential-coordinator')
            ->assertJsonPath(
                'bundle.signatures.0.expiresAt',
                data_get(DocumentSignature::query()->sole()->metadata, 'validity.expiresAt'),
            );
    }

    public function test_non_coordinator_can_download_verification_package_for_signed_revision(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('documents/1/revisions/1/example.pdf', 'payload');
        $this->configureVerificationPackageSigning();

        $signer = $this->createUserWithCredential(UserRole::Coordinator);
        $viewer = User::factory()->create([
            'role' => UserRole::NonCoordinator->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'totp-secret',
        ]);
        $document = $this->createDocumentWithRevision($signer);
        $signSession = $this->signSession($signer, $document);

        $this->mock(WebauthnAssertionService::class, function ($mock): void {
            $mock->shouldReceive('verifyAssertionPayload')
                ->once()
                ->andReturn(12);
        });

        $this->actingAs($signer)
            ->withSession($signSession)
            ->postJson(
                sprintf('/documents/%d/sign/verify', $document->id),
                $this->assertionPayload('credential-coordinator'),
            )
            ->assertOk();

        $response = $this->actingAs($viewer)
            ->get(sprintf('/documents/%d/verification-package', $document->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/zip');

        $zipContents = $response->getContent();

        $this->assertIsString($zipContents);
        $this->assertStringStartsWith("PK\x03\x04", $zipContents);
        $this->assertStringContainsString('example.pdf', $zipContents);
        $this->assertStringContainsString('payload', $zipContents);
        $this->assertStringContainsString('verification.json', $zipContents);
        $this->assertStringContainsString('"title": "Protected document"', $zipContents);
        $this->assertStringContainsString('"signatureStatus": "signed"', $zipContents);
        $this->assertStringContainsString('README.md', $zipContents);
        $this->assertStringContainsString('manifest.json', $zipContents);
        $this->assertStringContainsString('manifest.signature.json', $zipContents);
        $this->assertStringContainsString('verify.html.signature.json', $zipContents);
        $this->assertStringContainsString('verify.html.public.pem', $zipContents);
        $this->assertStringContainsString('verify.html.signature.bin', $zipContents);
        $this->assertStringContainsString('"packageType": "casa-monarca.document-verification"', $zipContents);
        $this->assertStringContainsString('"status": "signed"', $zipContents);
        $this->assertStringContainsString('"keyId": "test-package-key"', $zipContents);
        $this->assertStringContainsString('"purpose": "verify.html"', $zipContents);
        $this->assertStringContainsString('RSASSA-PKCS1-v1_5-SHA256', $zipContents);
        $this->assertStringContainsString('verify.html', $zipContents);
        $this->assertStringContainsString('Verification package', $zipContents);
        $this->assertStringContainsString('Verifier Tamper Check', $zipContents);
        $this->assertStringContainsString('openssl dgst -sha256 -verify verify.html.public.pem -signature verify.html.signature.bin verify.html', $zipContents);
        $this->assertStringContainsString('embeddedVerificationBundle', $zipContents);
        $this->assertStringContainsString('embeddedSignedManifest', $zipContents);
        $this->assertStringContainsString('Drop the confidential revision file here', $zipContents);
        $this->assertStringContainsString('Package fingerprints', $zipContents);
        $this->assertStringContainsString('Evidence hash', $zipContents);
        $this->assertStringContainsString('Verifier HTML hash', $zipContents);
        $this->assertStringContainsString('Expected document hash', $zipContents);
        $this->assertStringContainsString('Manifest signature', $zipContents);
        $this->assertStringContainsString('Package manifest signature', $zipContents);
        $this->assertStringContainsString('Signed verifier template hash', $zipContents);
        $this->assertStringContainsString('"revisionNumber": 1', $zipContents);
        $this->assertStringNotContainsString('id="jsonFile"', $zipContents);
        $this->assertDatabaseHas('audit_events', [
            'actor_user_id' => $viewer->id,
            'document_id' => $document->id,
            'event_type' => AuditEventType::DocumentVerificationPackageDownloaded->value,
            'outcome' => AuditEventOutcome::Success->value,
            'revision_id' => $document->currentRevision->id,
        ]);
    }

    private function configureVerificationPackageSigning(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->assertNotFalse($key);
        $this->assertTrue(openssl_pkey_export($key, $privateKey));

        $details = openssl_pkey_get_details($key);

        $this->assertIsArray($details);
        $this->assertArrayHasKey('key', $details);

        config()->set('documents.package_signing.key_id', 'test-package-key');
        config()->set('documents.package_signing.private_key', $privateKey);
        config()->set('documents.package_signing.public_key', $details['key']);
    }

    private function createUserWithCredential(UserRole $role): User
    {
        $user = User::factory()->create([
            'role' => $role->value,
            'two_factor_enabled' => true,
            'two_factor_secret' => 'totp-secret',
        ]);

        WebauthnCredential::query()->create([
            'user_id' => $user->id,
            'credential_id' => sprintf('credential-%s', $role->value),
            'public_key' => 'public-key',
            'public_key_algorithm' => -7,
            'name' => sprintf('%s key', $role->value),
            'sign_count' => 0,
            'transports' => ['usb'],
            'attestation_object' => 'attestation',
            'client_data_json' => 'client-data',
        ]);

        return $user;
    }

    private function createDocumentWithRevision(
        User $user,
        string $storagePath = 'documents/1/revisions/1/example.pdf',
    ): Document {
        $document = Document::factory()->create([
            'title' => 'Protected document',
            'owner_user_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
        ]);

        $revision = DocumentRevision::query()->create([
            'document_id' => $document->id,
            'parent_revision_id' => null,
            'created_by_user_id' => $user->id,
            'revision_number' => 1,
            'storage_disk' => 'local',
            'storage_path' => $storagePath,
            'original_file_name' => basename($storagePath),
            'mime_type' => 'application/pdf',
            'size_bytes' => 7,
            'sha256' => hash('sha256', 'payload'),
            'signature_status' => 'unsigned',
            'diff_metadata' => [
                'kind' => 'initial_upload',
            ],
        ]);

        $document->forceFill([
            'current_revision_id' => $revision->id,
        ])->save();

        return $document->fresh(['currentRevision']);
    }

    /**
     * @return array<string, mixed>
     */
    private function signSession(User $user, Document $document): array
    {
        $revision = $document->currentRevision()->firstOrFail();

        return $this->signSessionForRevision($user, $document, $revision);
    }

    /**
     * @return array<string, mixed>
     */
    private function signSessionForRevision(
        User $user,
        Document $document,
        DocumentRevision $revision,
    ): array {
        $intent = [
            'version' => 1,
            'purpose' => 'document-sign',
            'documentId' => $document->id,
            'revisionId' => $revision->id,
            'revisionNumber' => $revision->revision_number,
            'revisionSha256' => $revision->sha256,
            'userId' => $user->id,
            'origin' => 'http://localhost',
            'rpId' => 'localhost',
            'issuedAt' => now()->subSeconds(5)->utc()->toIso8601String(),
            'expiresAt' => now()->addMinute()->utc()->toIso8601String(),
            'nonce' => 'nonce-sign',
        ];
        $challenge = $this->expectedChallengeForIntent($intent);
        $challengeIntent = SecurityChallengeIntent::query()->create([
            'purpose' => 'document.sign',
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'actor_user_id' => $user->id,
            'target_type' => 'document_revision',
            'target_id' => $revision->id,
            'challenge_hash' => hash('sha256', $challenge),
            'payload' => $intent,
            'origin' => 'http://localhost',
            'rp_id' => 'localhost',
            'expires_at' => $intent['expiresAt'],
        ]);

        return [
            'documents.sign.webauthn.intent' => $intent,
            'documents.sign.webauthn.challenge_intent_id' => $challengeIntent->getKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function deleteSession(int $userId, int $documentId): array
    {
        $challenge = 'challenge-delete';
        $challengeIntent = SecurityChallengeIntent::query()->create([
            'purpose' => 'document.delete',
            'status' => SecurityChallengeIntent::STATUS_PENDING,
            'actor_user_id' => $userId,
            'target_type' => 'document',
            'target_id' => $documentId,
            'challenge_hash' => hash('sha256', $challenge),
            'payload' => [
                'action' => 'document.delete',
                'documentId' => $documentId,
                'origin' => 'http://localhost',
                'rpId' => 'localhost',
                'userId' => $userId,
                'version' => 1,
            ],
            'origin' => 'http://localhost',
            'rp_id' => 'localhost',
            'expires_at' => now('UTC')->addMinute(),
        ]);

        return [
            'documents.delete.webauthn.challenge' => $challenge,
            'documents.delete.webauthn.origin' => 'http://localhost',
            'documents.delete.webauthn.rp_id' => 'localhost',
            'documents.delete.webauthn.user_id' => $userId,
            'documents.delete.webauthn.document_id' => $documentId,
            'documents.delete.webauthn.challenge_intent_id' => $challengeIntent->getKey(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function revisionUpdateSession(
        User $user,
        Document $document,
        string $candidateFileName,
        string $candidatePayload,
    ): array {
        $revision = $document->currentRevision()->firstOrFail();

        return [
            'documents.revisions.update.webauthn.intent' => [
                'version' => 1,
                'purpose' => 'document-revision-update',
                'documentId' => $document->id,
                'revisionId' => $revision->id,
                'revisionNumber' => $revision->revision_number,
                'revisionSha256' => $revision->sha256,
                'userId' => $user->id,
                'origin' => 'http://localhost',
                'rpId' => 'localhost',
                'issuedAt' => now()->subSeconds(5)->utc()->toIso8601String(),
                'expiresAt' => now()->addMinute()->utc()->toIso8601String(),
                'nonce' => 'nonce-update',
                'candidateOriginalFileName' => $candidateFileName,
                'candidateSizeBytes' => strlen($candidatePayload),
                'candidateSha256' => hash('sha256', $candidatePayload),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
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

    private function expectedChallenge(array $signSession): string
    {
        return $this->expectedChallengeForIntent($signSession['documents.sign.webauthn.intent']);
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    private function expectedChallengeForIntent(array $intent): string
    {
        ksort($intent);

        return rtrim(strtr(base64_encode(hash(
            'sha256',
            json_encode($intent, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            true,
        )), '+/', '-_'), '=');
    }
}
