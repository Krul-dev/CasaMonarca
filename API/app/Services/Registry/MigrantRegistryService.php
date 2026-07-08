<?php

namespace App\Services;

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
    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly Request $request,
    ) {}

    public function create(User $user, array $payloadJson): MigrantRegistryEntry
    {
        return DB::transaction(function () use ($user, $payloadJson) {
            $entry = MigrantRegistryEntry::create([
                'created_by' => $user->id,
                'created_by_role' => $user->role ?? 'volunteer',
                'current_status' => 'draft',
                'current_assignee_role' => 'operator',
                'payload_json' => $payloadJson,
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