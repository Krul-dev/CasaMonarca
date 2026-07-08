<?php

namespace App\Services;

use App\Enums\AuditEventType;
use App\Models\MigrantArcoRequest;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MigrantArcoService
{
    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly Request $request,
    ) {}

    public function create(User $user, array $data): MigrantArcoRequest
    {
        return DB::transaction(function () use ($user, $data) {
            $request = MigrantArcoRequest::create([
                'registry_entry_id' => $data['registry_entry_id'],
                'requested_by' => $user->id,
                'requested_by_role' => $user->role ?? 'operator',
                'request_type' => $data['request_type'],
                'reason' => $data['reason'],
                'status' => 'opened_by_operator',
                'escalated_to_admin' => false,
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantArcoRequested,
                $user,
                resource: [
                    'type' => MigrantArcoRequest::class,
                    'id' => $request->id,
                ],
                metadata: [
                    'registry_entry_id' => $request->registry_entry_id,
                    'request_type' => $request->request_type,
                ],
            );

            return $request;
        });
    }

    public function resolve(
        User $user,
        MigrantArcoRequest $request,
        array $data
    ): MigrantArcoRequest {
        return DB::transaction(function () use ($user, $request, $data) {
            $request->update([
                'status' => $data['decision'] === 'approve'
                    ? 'approved'
                    : 'rejected',
                'resolved_by' => $user->id,
                'resolved_by_role' => $user->role ?? 'coordinator',
                'resolution_reason' => $data['reason'] ?? null,
                'escalated_to_admin' => (bool) ($data['needs_admin_deletion'] ?? false),
            ]);

            $this->auditService->success(
                $this->request,
                AuditEventType::MigrantArcoResolved,
                $user,
                resource: [
                    'type' => MigrantArcoRequest::class,
                    'id' => $request->id,
                ],
                metadata: [
                    'decision' => $data['decision'],
                    'status' => $request->status,
                    'needs_admin_deletion' => (bool) ($data['needs_admin_deletion'] ?? false),
                ],
            );

            return $request->fresh();
        });
    }
}