<?php

namespace App\Services\Documents;

use App\Models\DocumentRevision;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequirement;
use App\Models\User;
use Illuminate\Support\Collection;

class DocumentSignatureRequirementService
{
    public function canSign(DocumentRevision $revision, User $user): bool
    {
        $requirements = $this->pendingRequirements($revision);

        if ($requirements->isEmpty()) {
            return true;
        }

        $target = $this->nextRequirementForUser($revision, $user);

        return $target instanceof DocumentSignatureRequirement;
    }

    public function rejectionMessage(DocumentRevision $revision, User $user): string
    {
        $requirements = $this->pendingRequirements($revision);

        if ($requirements->isEmpty()) {
            return 'This document has no pending signature requirement for this account.';
        }

        if ($revision->signature_order_enforced) {
            $next = $requirements->first();

            if ($next instanceof DocumentSignatureRequirement) {
                return sprintf(
                    'This document requires the next signature from %s.',
                    $this->requirementLabel($next),
                );
            }
        }

        return 'This account is not assigned to any remaining signature requirement for this document.';
    }

    public function fulfillForSignature(DocumentRevision $revision, User $user, DocumentSignature $signature): void
    {
        $requirement = $this->nextRequirementForUser($revision, $user);

        if (! $requirement instanceof DocumentSignatureRequirement) {
            return;
        }

        $requirement->forceFill([
            'fulfilled_by_signature_id' => $signature->getKey(),
            'fulfilled_at' => now('UTC'),
        ])->save();
    }

    public function allRequirementsFulfilled(DocumentRevision $revision): bool
    {
        $revision->loadMissing('signatureRequirements');

        return $revision->signatureRequirements->isNotEmpty()
            && $revision->signatureRequirements->every(
                fn (DocumentSignatureRequirement $requirement): bool => $requirement->isFulfilled(),
            );
    }

    private function nextRequirementForUser(DocumentRevision $revision, User $user): ?DocumentSignatureRequirement
    {
        $requirements = $this->pendingRequirements($revision);

        if ($revision->signature_order_enforced) {
            $next = $requirements->first();

            return $next instanceof DocumentSignatureRequirement && $next->matchesUser($user)
                ? $next
                : null;
        }

        return $requirements->first(
            fn (DocumentSignatureRequirement $requirement): bool => $requirement->signer_user_id !== null
                && $requirement->matchesUser($user),
        ) ?? $requirements->first(
            fn (DocumentSignatureRequirement $requirement): bool => $requirement->matchesUser($user),
        );
    }

    /**
     * @return Collection<int, DocumentSignatureRequirement>
     */
    private function pendingRequirements(DocumentRevision $revision): Collection
    {
        $revision->loadMissing('signatureRequirements');

        return $revision->signatureRequirements
            ->filter(fn (DocumentSignatureRequirement $requirement): bool => ! $requirement->isFulfilled())
            ->sortBy('sequence')
            ->values();
    }

    private function requirementLabel(DocumentSignatureRequirement $requirement): string
    {
        if ($requirement->signerUser) {
            return $requirement->signerUser->email;
        }

        return $requirement->signer_role?->value ?? 'an assigned signer';
    }
}
