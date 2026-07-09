<?php

namespace App\Services\Registry;

use App\Enums\AuditEventType;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MigrantRegistryService
{
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DELETED = 'deleted_by_admin';

    public const ACTION_CREATE = 'create';

    public const ACTION_UPDATE = 'update';

    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly Request $request,
    ) {}

    public function create(User $user, array $payloadJson): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($user, $payloadJson) {
            $entry = MigrantRegistryEntry::create([
                'created_by' => $user->id,
                'created_by_role' => $user->role?->value ?? 'volunteer',
                'current_status' => self::STATUS_PENDING_APPROVAL,
                'current_assignee_role' => 'coordinator',
                'pending_action' => self::ACTION_CREATE,
                'pending_requested_by' => $user->id,
                'pending_requested_by_role' => $user->role?->value ?? 'volunteer',
                'payload_json' => $payloadJson,
            ]);

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => null,
                'to_status' => self::STATUS_PENDING_APPROVAL,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'volunteer',
                'reason' => 'Registration submitted for coordinator/admin approval',
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryCreated,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
            );

            return $entry;
        });
    }

    public function requestUpdate(
        User $user,
        MigrantRegistryEntry $entry,
        array $payloadJson,
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $payloadJson) {
            if ($entry->current_status === self::STATUS_PENDING_APPROVAL) {
                abort(409, 'This migrant registration is already pending approval.');
            }

            if ($entry->current_status === self::STATUS_DELETED) {
                abort(409, 'Deleted migrant registrations cannot be modified.');
            }

            $fromStatus = (string) $entry->current_status;

            $entry->forceFill([
                'current_status' => self::STATUS_PENDING_APPROVAL,
                'current_assignee_role' => 'coordinator',
                'pending_action' => self::ACTION_UPDATE,
                'pending_requested_by' => $user->id,
                'pending_requested_by_role' => $user->role?->value ?? 'volunteer',
                'pending_payload_json' => $payloadJson,
            ])->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => $fromStatus,
                'to_status' => self::STATUS_PENDING_APPROVAL,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'volunteer',
                'reason' => 'Registration modification submitted for coordinator/admin approval',
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryUpdateRequested,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
                metadata: [
                    'previousStatus' => $fromStatus,
                    'status' => self::STATUS_PENDING_APPROVAL,
                ],
            );

            return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $signatureData
     */
    public function resolveApproval(
        User $user,
        MigrantRegistryEntry $entry,
        string $decision,
        ?string $reason,
        array $signatureData,
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $decision, $reason, $signatureData) {
            $fromStatus = (string) $entry->current_status;
            $pendingAction = (string) ($entry->pending_action ?? self::ACTION_CREATE);
            $pendingPayload = $entry->pending_payload_json;
            $toStatus = match (true) {
                $decision === 'approve' => self::STATUS_APPROVED,
                $pendingAction === self::ACTION_UPDATE => self::STATUS_APPROVED,
                default => self::STATUS_REJECTED,
            };

            $signature = MigrantRegistrySignature::create([
                'registry_entry_id' => $entry->id,
                'actor_user_id' => $user->id,
                'actor_role' => $user->role?->value ?? 'coordinator',
                'action_type' => $decision === 'approve' ? 'approve' : 'reject',
                'algorithm' => 'webauthn-passkey',
                'signature_payload' => json_encode($signatureData, JSON_THROW_ON_ERROR),
                'public_key_ref' => (string) ($signatureData['credentialId'] ?? ''),
                'verified_at' => now(),
            ]);

            $entryUpdates = [
                'current_status' => $toStatus,
                'current_assignee_role' => null,
                'pending_action' => null,
                'pending_requested_by' => null,
                'pending_requested_by_role' => null,
                'pending_payload_json' => null,
            ];

            if ($decision === 'approve' && $pendingAction === self::ACTION_UPDATE && is_array($pendingPayload)) {
                $entryUpdates = [
                    ...$entryUpdates,
                    'payload_json' => $pendingPayload,
                ];
            }

            $entry->forceFill($entryUpdates)->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'coordinator',
                'reason' => $reason,
                'signature_id' => $signature->id,
            ]);

            $this->auditService->success(
                $this->request,
                $decision === 'approve'
                    ? AuditEventType::MigrantRegistryApproved
                    : AuditEventType::MigrantRegistryRejected,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
                metadata: [
                    'decision' => $decision,
                    'reason' => $reason,
                    'previousStatus' => $fromStatus,
                    'status' => $toStatus,
                    'pendingAction' => $pendingAction,
                ],
            );

            return $entry->fresh(['creator:id,name,email,role', 'signatures', 'statusHistory']) ?? $entry;
        });
    }

    public function delete(User $user, MigrantRegistryEntry $entry): void
    {
        DB::transaction(function () use ($user, $entry) {
            $fromStatus = (string) $entry->current_status;

            $entry->forceFill([
                'current_status' => self::STATUS_DELETED,
                'current_assignee_role' => null,
                'pending_action' => null,
                'pending_requested_by' => null,
                'pending_requested_by_role' => null,
                'pending_payload_json' => null,
            ])->save();

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => $fromStatus,
                'to_status' => self::STATUS_DELETED,
                'changed_by' => $user->id,
                'changed_by_role' => $user->role?->value ?? 'admin',
                'reason' => 'Deleted by admin',
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistryDeleted,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
                metadata: [
                    'previousStatus' => $fromStatus,
                    'status' => self::STATUS_DELETED,
                ],
            );

            $entry->delete();
        });
    }

    public function submit(
        User $user,
        MigrantRegistryEntry $entry,
        array $signatureData
    ): MigrantRegistryEntry {
        return DB::transaction(function () use ($user, $entry, $signatureData) {
            $signature = MigrantRegistrySignature::create([
                'registry_entry_id' => $entry->id,
                'actor_user_id' => $user->id,
                'actor_role' => $user->role ?? 'volunteer',
                'action_type' => 'submit',
                'algorithm' => 'ES256',
                'signature_payload' => $signatureData['signature_payload'],
                'public_key_ref' => $signatureData['public_key_ref'] ?? null,
                'verified_at' => now(),
            ]);

            $entry->update([
                'current_status' => 'submitted_by_volunteer',
                'current_assignee_role' => 'operator',
            ]);

            MigrantRegistryStatusHistory::create([
                'registry_entry_id' => $entry->id,
                'from_status' => 'draft',
                'to_status' => 'submitted_by_volunteer',
                'changed_by' => $user->id,
                'changed_by_role' => $user->role ?? 'volunteer',
                'reason' => 'Submitted by volunteer',
                'signature_id' => $signature->id,
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantRegistrySubmitted,
                $user,
                resource: [
                    'type' => MigrantRegistryEntry::class,
                    'id' => $entry->id,
                ],
            );

            return $entry->fresh([
                'signatures',
                'statusHistory',
            ]);
        });
    }
}
