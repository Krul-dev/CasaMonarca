<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentSignatureRequirement;
use App\Models\User;
use Illuminate\Support\Collection;

class DocumentSignatureRequirementService
{
    public function canSign(Document $document, User $user): bool
    {
        $requirements = $this->pendingRequirements($document);

        if ($requirements->isEmpty()) {
            return true;
        }

        $target = $this->nextRequirementForUser($document, $user);

        return $target instanceof DocumentSignatureRequirement;
    }

    public function rejectionMessage(Document $document, User $user): string
    {
        $requirements = $this->pendingRequirements($document);

        if ($requirements->isEmpty()) {
            return 'This document has no pending signature requirement for this account.';
        }

        if ($document->signature_order_enforced) {
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

    public function fulfillForSignature(Document $document, User $user, DocumentSignature $signature): void
    {
        $requirement = $this->nextRequirementForUser($document, $user);

        if (! $requirement instanceof DocumentSignatureRequirement) {
            return;
        }

        $requirement->forceFill([
            'fulfilled_by_signature_id' => $signature->getKey(),
            'fulfilled_at' => now('UTC'),
        ])->save();
    }

    public function allRequirementsFulfilled(Document $document): bool
    {
        $document->loadMissing('signatureRequirements');

        return $document->signatureRequirements->isNotEmpty()
            && $document->signatureRequirements->every(
                fn (DocumentSignatureRequirement $requirement): bool => $requirement->isFulfilled(),
            );
    }

    private function nextRequirementForUser(Document $document, User $user): ?DocumentSignatureRequirement
    {
        $requirements = $this->pendingRequirements($document);

        if ($document->signature_order_enforced) {
            $next = $requirements->first();

            return $next instanceof DocumentSignatureRequirement && $next->matchesUser($user)
                ? $next
                : null;
        }

        return $requirements->first(
            fn (DocumentSignatureRequirement $requirement): bool => $requirement->matchesUser($user),
        );
    }

    /**
     * @return Collection<int, DocumentSignatureRequirement>
     */
    private function pendingRequirements(Document $document): Collection
    {
        $document->loadMissing('signatureRequirements');

        return $document->signatureRequirements
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
