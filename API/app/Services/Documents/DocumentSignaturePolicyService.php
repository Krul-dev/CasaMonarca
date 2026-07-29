<?php

namespace App\Services\Documents;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignatureRequirement;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentSignaturePolicyService
{
    public const MAX_REQUIREMENTS = 20;

    public function __construct(private readonly AuditEventService $auditEventService) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(DocumentRevision $revision): array
    {
        $revision->loadMissing([
            'signatureRequirements.signerUser',
            'signatureRequirements.fulfilledBySignature.signedBy',
        ]);

        return [
            'version' => (int) $revision->signature_policy_version,
            'signatureOrderEnforced' => (bool) $revision->signature_order_enforced,
            'requirements' => $revision->signatureRequirements
                ->sortBy('sequence')
                ->values()
                ->map(fn (DocumentSignatureRequirement $requirement): array => $this->requirementPayload($requirement))
                ->all(),
        ];
    }

    /**
     * @return array{roles: list<array{label: string, value: string}>, users: list<array<string, mixed>>}
     */
    public function signerOptions(): array
    {
        $roles = [UserRole::Admin, UserRole::Coordinator];
        $users = User::query()
            ->withCount('webauthnCredentials')
            ->whereIn('role', array_map(fn (UserRole $role): string => $role->value, $roles))
            ->orderBy('name')
            ->orderBy('email')
            ->get()
            ->filter(fn (User $user): bool => $user->isActiveAccount() && $user->webauthn_credentials_count > 0)
            ->map(fn (User $user): array => [
                'id' => (int) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
            ])
            ->values()
            ->all();

        return [
            'roles' => [
                ['label' => 'Administrator', 'value' => UserRole::Admin->value],
                ['label' => 'Coordinator', 'value' => UserRole::Coordinator->value],
            ],
            'users' => $users,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     * @return array<string, mixed>
     */
    public function update(
        Request $request,
        User $actor,
        Document $document,
        DocumentRevision $revision,
        int $expectedVersion,
        bool $signatureOrderEnforced,
        array $requirements,
    ): array {
        if (count($requirements) > self::MAX_REQUIREMENTS) {
            throw ValidationException::withMessages([
                'requirements' => ['A signature policy may contain at most 20 requirements.'],
            ]);
        }

        $before = $this->toArray($revision);

        $updated = DB::transaction(function () use (
            $revision,
            $expectedVersion,
            $signatureOrderEnforced,
            $requirements,
        ): DocumentRevision {
            $lockedRevision = DocumentRevision::query()
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRevision->load([
                'signatureRequirements.signerUser',
                'signatureRequirements.fulfilledBySignature.signedBy',
                'signatures',
            ]);

            if ((int) $lockedRevision->signature_policy_version !== $expectedVersion) {
                abort(409, 'The signature policy changed. Reload the revision and try again.');
            }

            $normalized = $this->normalizeAndValidateRequirements($lockedRevision, $requirements);
            $this->validateFeasibility($lockedRevision, $normalized);

            $existing = $lockedRevision->signatureRequirements->keyBy(
                fn (DocumentSignatureRequirement $requirement): int => (int) $requirement->getKey(),
            );
            $retainedIds = collect($normalized)
                ->pluck('id')
                ->filter(fn (mixed $id): bool => is_int($id))
                ->all();

            $existing
                ->filter(fn (DocumentSignatureRequirement $requirement): bool => ! $requirement->isFulfilled())
                ->reject(fn (DocumentSignatureRequirement $requirement): bool => in_array((int) $requirement->getKey(), $retainedIds, true))
                ->each->delete();

            foreach ($normalized as $index => $item) {
                $requirement = isset($item['id'])
                    ? $existing->get($item['id'])
                    : new DocumentSignatureRequirement([
                        'document_revision_id' => $lockedRevision->getKey(),
                    ]);

                if (! $requirement instanceof DocumentSignatureRequirement) {
                    throw ValidationException::withMessages([
                        'requirements' => ['One or more signature requirements no longer exist.'],
                    ]);
                }

                if (! $requirement->isFulfilled()) {
                    $requirement->forceFill([
                        'sequence' => $index + 1,
                        'signer_role' => $item['type'] === 'role' ? $item['role'] : null,
                        'signer_user_id' => $item['type'] === 'user' ? $item['userId'] : null,
                    ])->save();
                } elseif ((int) $requirement->sequence !== $index + 1) {
                    $requirement->forceFill(['sequence' => $index + 1])->save();
                }
            }

            $lockedRevision->forceFill([
                'signature_order_enforced' => $signatureOrderEnforced,
                'signature_policy_version' => (int) $lockedRevision->signature_policy_version + 1,
            ])->save();

            $this->recalculateStatus($lockedRevision);

            return $lockedRevision->fresh([
                'signatureRequirements.signerUser',
                'signatureRequirements.fulfilledBySignature.signedBy',
                'signatures',
            ]) ?? $lockedRevision;
        });

        $after = $this->toArray($updated);

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentSignaturePolicyUpdated,
            $actor,
            [
                'type' => 'document_revision',
                'id' => $updated->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $updated->getKey(),
            ],
            [
                'before' => $this->auditSummary($before),
                'after' => $this->auditSummary($after),
            ],
        );

        return $after;
    }

    public function clonePolicy(DocumentRevision $source, DocumentRevision $target): void
    {
        $source->loadMissing('signatureRequirements');
        $target->forceFill([
            'signature_order_enforced' => (bool) $source->signature_order_enforced,
            'signature_policy_version' => 1,
        ])->save();

        foreach ($source->signatureRequirements->sortBy('sequence') as $requirement) {
            $target->signatureRequirements()->create([
                'sequence' => $requirement->sequence,
                'signer_role' => $requirement->signer_role?->value,
                'signer_user_id' => $requirement->signer_user_id,
                'fulfilled_by_signature_id' => null,
                'fulfilled_at' => null,
            ]);
        }
    }

    public function recalculateStatus(DocumentRevision $revision): void
    {
        $hasSignatures = $revision->signatures()->exists();
        $hasPendingRequirements = $revision->signatureRequirements()
            ->whereNull('fulfilled_at')
            ->whereNull('fulfilled_by_signature_id')
            ->exists();

        $status = ! $hasSignatures
            ? 'unsigned'
            : ($hasPendingRequirements ? 'partially_signed' : 'signed');

        if ($revision->signature_status !== $status) {
            $revision->forceFill(['signature_status' => $status])->save();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $submitted
     * @return list<array{id?: int, type: string, role?: string, userId?: int}>
     */
    private function normalizeAndValidateRequirements(DocumentRevision $revision, array $submitted): array
    {
        $existing = $revision->signatureRequirements->keyBy(
            fn (DocumentSignatureRequirement $requirement): int => (int) $requirement->getKey(),
        );
        $seenIds = [];
        $seenExplicitUsers = [];
        $normalized = [];
        $fulfilledIds = $revision->signatureRequirements
            ->filter->isFulfilled()
            ->sortBy('sequence')
            ->map(fn (DocumentSignatureRequirement $requirement): int => (int) $requirement->getKey())
            ->values()
            ->all();
        $submittedFulfilledIds = [];

        foreach ($submitted as $index => $item) {
            $path = "requirements.{$index}";
            $id = isset($item['id']) && is_numeric($item['id']) ? (int) $item['id'] : null;
            $type = $item['type'] ?? null;

            if ($id !== null) {
                if (isset($seenIds[$id]) || ! $existing->has($id)) {
                    throw ValidationException::withMessages([
                        "{$path}.id" => ['The selected signature requirement is invalid or duplicated.'],
                    ]);
                }
                $seenIds[$id] = true;
            }

            if (! in_array($type, ['role', 'user'], true)) {
                throw ValidationException::withMessages([
                    "{$path}.type" => ['Requirement type must be role or user.'],
                ]);
            }

            $entry = ['type' => $type];
            if ($id !== null) {
                $entry['id'] = $id;
            }

            if ($type === 'role') {
                $role = $item['role'] ?? null;
                if (! in_array($role, [UserRole::Admin->value, UserRole::Coordinator->value], true)) {
                    throw ValidationException::withMessages([
                        "{$path}.role" => ['Only administrator and coordinator signature roles are supported.'],
                    ]);
                }
                $entry['role'] = $role;
            } else {
                $userId = isset($item['userId']) && is_numeric($item['userId'])
                    ? (int) $item['userId']
                    : null;
                if ($userId === null || isset($seenExplicitUsers[$userId])) {
                    throw ValidationException::withMessages([
                        "{$path}.userId" => ['A specific signer may only be assigned once.'],
                    ]);
                }
                $seenExplicitUsers[$userId] = true;
                $entry['userId'] = $userId;
            }

            if ($id !== null) {
                /** @var DocumentSignatureRequirement $current */
                $current = $existing->get($id);
                if ($current->isFulfilled()) {
                    $submittedFulfilledIds[] = $id;
                    $unchanged = ($type === 'role'
                            && $current->signer_role?->value === ($entry['role'] ?? null)
                            && $current->signer_user_id === null)
                        || ($type === 'user'
                            && (int) $current->signer_user_id === (int) ($entry['userId'] ?? 0)
                            && $current->signer_role === null);
                    if (! $unchanged) {
                        throw ValidationException::withMessages([
                            $path => ['Fulfilled signature requirements cannot be changed.'],
                        ]);
                    }
                }
            }

            $normalized[] = $entry;
        }

        if ($submittedFulfilledIds !== $fulfilledIds) {
            throw ValidationException::withMessages([
                'requirements' => ['Fulfilled signature requirements must remain in their existing order.'],
            ]);
        }

        $this->validateFulfilledBarriers($revision->signatureRequirements, $normalized);

        return $normalized;
    }

    /**
     * @param  Collection<int, DocumentSignatureRequirement>  $existing
     * @param  list<array<string, mixed>>  $submitted
     */
    private function validateFulfilledBarriers(Collection $existing, array $submitted): void
    {
        $existingSegments = [];
        $fulfilledSeen = 0;
        foreach ($existing->sortBy('sequence') as $requirement) {
            if ($requirement->isFulfilled()) {
                $fulfilledSeen++;
            } else {
                $existingSegments[(int) $requirement->getKey()] = $fulfilledSeen;
            }
        }

        $fulfilledSeen = 0;
        foreach ($submitted as $item) {
            $id = $item['id'] ?? null;
            if (is_int($id) && $existing->firstWhere('id', $id)?->isFulfilled()) {
                $fulfilledSeen++;

                continue;
            }

            if (is_int($id) && isset($existingSegments[$id]) && $existingSegments[$id] !== $fulfilledSeen) {
                throw ValidationException::withMessages([
                    'requirements' => ['Pending requirements cannot be moved across a fulfilled requirement.'],
                ]);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $requirements
     */
    private function validateFeasibility(DocumentRevision $revision, array $requirements): void
    {
        $pending = collect($requirements)->filter(function (array $item) use ($revision): bool {
            if (! isset($item['id'])) {
                return true;
            }

            return ! $revision->signatureRequirements
                ->firstWhere('id', $item['id'])
                ?->isFulfilled();
        })->values();

        if ($pending->isEmpty()) {
            return;
        }

        $signedUserIds = $revision->signatures
            ->pluck('signed_by_user_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $eligibleUsers = User::query()
            ->withCount('webauthnCredentials')
            ->whereIn('role', [UserRole::Admin->value, UserRole::Coordinator->value])
            ->get()
            ->filter(fn (User $user): bool => $user->isActiveAccount()
                && $user->webauthn_credentials_count > 0
                && ! in_array((int) $user->getKey(), $signedUserIds, true));

        $candidates = $pending->map(function (array $item) use ($eligibleUsers): array {
            return $eligibleUsers
                ->filter(fn (User $user): bool => $item['type'] === 'user'
                    ? (int) $user->getKey() === (int) $item['userId']
                    : $user->role?->value === $item['role'])
                ->map(fn (User $user): int => (int) $user->getKey())
                ->values()
                ->all();
        })->all();

        $assignedRequirementByUser = [];
        foreach (array_keys($candidates) as $requirementIndex) {
            $visited = [];
            if (! $this->assignDistinctSigner($requirementIndex, $candidates, $assignedRequirementByUser, $visited)) {
                throw ValidationException::withMessages([
                    'requirements' => ['Every pending requirement must be fulfillable by a distinct active signer with a passkey.'],
                ]);
            }
        }
    }

    /**
     * @param  array<int, list<int>>  $candidates
     * @param  array<int, int>  $assignedRequirementByUser
     * @param  array<int, bool>  $visited
     */
    private function assignDistinctSigner(
        int $requirementIndex,
        array $candidates,
        array &$assignedRequirementByUser,
        array &$visited,
    ): bool {
        foreach ($candidates[$requirementIndex] ?? [] as $userId) {
            if (isset($visited[$userId])) {
                continue;
            }
            $visited[$userId] = true;

            if (
                ! isset($assignedRequirementByUser[$userId])
                || $this->assignDistinctSigner(
                    $assignedRequirementByUser[$userId],
                    $candidates,
                    $assignedRequirementByUser,
                    $visited,
                )
            ) {
                $assignedRequirementByUser[$userId] = $requirementIndex;

                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirementPayload(DocumentSignatureRequirement $requirement): array
    {
        $fulfilledSignature = $requirement->fulfilledBySignature;

        return [
            'id' => (int) $requirement->getKey(),
            'sequence' => (int) $requirement->sequence,
            'type' => $requirement->signer_user_id !== null ? 'user' : 'role',
            'signerRole' => $requirement->signer_role?->value,
            'signerUser' => $requirement->signer_user_id !== null ? [
                'id' => $requirement->signerUser?->getKey(),
                'name' => $requirement->signerUser?->name,
                'email' => $requirement->signerUser?->email,
                'role' => $requirement->signerUser?->role?->value,
            ] : null,
            'fulfilledAt' => $requirement->fulfilled_at?->toIso8601String(),
            'fulfilledBySignatureId' => $requirement->fulfilled_by_signature_id,
            'fulfilledBy' => $fulfilledSignature ? [
                'id' => $fulfilledSignature->signedBy?->getKey(),
                'name' => $fulfilledSignature->signedBy?->name,
                'email' => $fulfilledSignature->signedBy?->email,
                'role' => $fulfilledSignature->signedBy?->role?->value,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function auditSummary(array $policy): array
    {
        return [
            'version' => $policy['version'],
            'signatureOrderEnforced' => $policy['signatureOrderEnforced'],
            'requirements' => collect($policy['requirements'])->map(fn (array $requirement): array => [
                'id' => $requirement['id'],
                'sequence' => $requirement['sequence'],
                'type' => $requirement['type'],
                'signerRole' => $requirement['signerRole'],
                'signerUserId' => data_get($requirement, 'signerUser.id'),
                'fulfilledBySignatureId' => $requirement['fulfilledBySignatureId'],
                'fulfilledAt' => $requirement['fulfilledAt'],
            ])->all(),
        ];
    }
}
