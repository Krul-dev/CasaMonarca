<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\DocumentApprovalViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DocumentApprovalApproveController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentApprovalViewService $documentApprovalViewService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, Document $document): JsonResponse
    {
        $validated = $request->validate([
            'approvalNote' => ['nullable', 'string', 'max:2000'],
            'signatureOrderEnforced' => ['sometimes', 'boolean'],
            'requirements' => ['sometimes', 'array', 'max:20'],
            'requirements.*.type' => ['required_with:requirements', 'string', Rule::in(['role', 'user'])],
            'requirements.*.role' => ['nullable', 'required_if:requirements.*.type,role', 'string', Rule::in([
                UserRole::Admin->value,
                UserRole::Coordinator->value,
            ])],
            'requirements.*.userId' => ['nullable', 'required_if:requirements.*.type,user', 'integer', 'exists:users,id'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if ($document->status !== 'pending_approval') {
            return response()->json([
                'message' => 'This document is not pending approval.',
                'error' => [
                    'code' => 'document_not_pending_approval',
                ],
            ], 409);
        }

        $requirements = $this->normalizeRequirements($validated['requirements'] ?? []);

        /** @var Document $approvedDocument */
        $approvedDocument = DB::transaction(function () use ($actor, $document, $requirements, $validated): Document {
            $document->forceFill([
                'status' => 'active',
                'approved_at' => now('UTC'),
                'approved_by_user_id' => $actor->getKey(),
                'approval_note' => $validated['approvalNote'] ?? null,
                'signature_order_enforced' => (bool) ($validated['signatureOrderEnforced'] ?? false),
            ])->save();

            $document->signatureRequirements()->delete();

            foreach ($requirements as $index => $requirement) {
                $document->signatureRequirements()->create([
                    'sequence' => $index + 1,
                    'signer_role' => $requirement['role'],
                    'signer_user_id' => $requirement['userId'],
                ]);
            }

            return $document->fresh([
                'uploadedBy',
                'approvedBy',
                'currentRevision',
                'signatureRequirements.signerUser',
            ]);
        });

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentApproved,
            $actor,
            [
                'type' => 'document',
                'id' => $approvedDocument->getKey(),
                'documentId' => $approvedDocument->getKey(),
                'revisionId' => $approvedDocument->current_revision_id,
            ],
            [
                'requirementCount' => count($requirements),
                'signatureOrderEnforced' => (bool) $approvedDocument->signature_order_enforced,
            ],
        );

        return response()->json([
            'message' => 'Document approved successfully.',
            'document' => $this->documentApprovalViewService->serialize($approvedDocument),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $requirements
     * @return array<int, array{role: string|null, userId: int|null}>
     *
     * @throws ValidationException
     */
    private function normalizeRequirements(array $requirements): array
    {
        $normalized = [];

        foreach ($requirements as $index => $requirement) {
            $type = (string) ($requirement['type'] ?? '');

            if ($type === 'role') {
                $role = (string) ($requirement['role'] ?? '');

                if (! in_array($role, [UserRole::Admin->value, UserRole::Coordinator->value], true)) {
                    throw ValidationException::withMessages([
                        sprintf('requirements.%d.role', $index) => [
                            'Choose a signing-capable role.',
                        ],
                    ]);
                }

                $normalized[] = [
                    'role' => $role,
                    'userId' => null,
                ];

                continue;
            }

            $user = User::query()
                ->withCount('webauthnCredentials')
                ->find((int) ($requirement['userId'] ?? 0));

            if (
                ! $user instanceof User ||
                ! in_array($user->role, [UserRole::Admin, UserRole::Coordinator], true) ||
                ! $user->isActiveAccount() ||
                (int) ($user->webauthn_credentials_count ?? 0) < 1
            ) {
                throw ValidationException::withMessages([
                    sprintf('requirements.%d.userId', $index) => [
                        'Choose an active admin or coordinator with at least one passkey.',
                    ],
                ]);
            }

            $normalized[] = [
                'role' => null,
                'userId' => (int) $user->getKey(),
            ];
        }

        return $normalized;
    }
}
