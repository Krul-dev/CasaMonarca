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
            $toStatus = $decision === 'approve'
                ? self::STATUS_APPROVED
                : self::STATUS_REJECTED;

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

            $entry->forceFill([
                'current_status' => $toStatus,
                'current_assignee_role' => null,
            ])->save();

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
                ],
            );

            return $entry->fresh(['signatures', 'statusHistory']) ?? $entry;
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
