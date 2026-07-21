<?php

namespace App\Services\Documents;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DocumentAuthorizationService
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentSignatureRequirementService $documentSignatureRequirementService,
    ) {}

    public function canViewDocuments(User $user): bool
    {
        return in_array($user->role?->value, [
            UserRole::Admin->value,
            UserRole::Coordinator->value,
            UserRole::NonCoordinator->value,
        ], true);
    }

    public function canUpdateDocument(User $user): bool
    {
        return in_array($user->role?->value, [
            UserRole::Admin->value,
            UserRole::Coordinator->value,
        ], true);
    }

    public function canApproveDocuments(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function canReadDocument(User $user, Document $document): bool
    {
        if (! $document->isApproved()) {
            return false;
        }

        return $this->canViewDocuments($user);
    }

    public function canDeleteDocument(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function canReadRevision(User $user, Document $document, DocumentRevision $revision): bool
    {
        if (! $document->isApproved()) {
            return false;
        }

        if ($this->isCurrentRevision($document, $revision)) {
            return $this->canViewDocuments($user);
        }

        return match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coordinator => $this->ownsRevision($user, $revision),
            default => false,
        };
    }

    public function canSignRevision(User $user, Document $document, DocumentRevision $revision): bool
    {
        if (! $document->isApproved()) {
            return false;
        }

        $roleAllowed = match ($user->role) {
            UserRole::Admin => true,
            UserRole::Coordinator => $this->isCurrentRevision($document, $revision)
                || $this->ownsRevision($user, $revision),
            default => false,
        };

        return $roleAllowed && $this->documentSignatureRequirementService->canSign($document, $user);
    }

    public function signatureRequirementRejectionMessage(Document $document, User $user): string
    {
        return $this->documentSignatureRequirementService->rejectionMessage($document, $user);
    }

    /**
     * @return array{
     *     canDeleteDocument: bool,
     *     canDownloadCurrent: bool,
     *     canReadCurrentVerificationBundle: bool,
     *     canSignCurrent: bool,
     *     canUploadRevision: bool,
     * }
     */
    public function documentCapabilities(User $user, Document $document): array
    {
        $currentRevision = $document->currentRevision;
        $canReadCurrent = $currentRevision instanceof DocumentRevision
            && $this->canReadRevision($user, $document, $currentRevision);
        $canSignCurrent = $currentRevision instanceof DocumentRevision
            && $this->canSignRevision($user, $document, $currentRevision);

        return [
            'canDeleteDocument' => $this->canDeleteDocument($user),
            'canDownloadCurrent' => $canReadCurrent,
            'canReadCurrentVerificationBundle' => $canReadCurrent,
            'canSignCurrent' => $canSignCurrent,
            'canUploadRevision' => $this->canUpdateDocument($user),
        ];
    }

    /**
     * @return array{
     *     canDownload: bool,
     *     canReadVerificationBundle: bool,
     *     canSign: bool,
     * }
     */
    public function revisionCapabilities(User $user, Document $document, DocumentRevision $revision): array
    {
        $canRead = $this->canReadRevision($user, $document, $revision);

        return [
            'canDownload' => $canRead,
            'canReadVerificationBundle' => $canRead,
            'canSign' => $this->canSignRevision($user, $document, $revision),
        ];
    }

    /**
     * @param  iterable<DocumentRevision>  $revisions
     * @return Collection<int, DocumentRevision>
     */
    public function visibleRevisions(User $user, Document $document, iterable $revisions): Collection
    {
        return collect($revisions)
            ->filter(fn (mixed $revision): bool => $revision instanceof DocumentRevision
                && $this->canReadRevision($user, $document, $revision))
            ->values();
    }

    public function forbiddenResponse(
        Request $request,
        ?User $user,
        string $action,
        ?Document $document = null,
        ?DocumentRevision $revision = null,
    ): JsonResponse {
        $resourceType = null;
        $resourceId = null;

        if ($revision instanceof DocumentRevision) {
            $resourceType = 'document_revision';
            $resourceId = $revision->getKey();
        } elseif ($document instanceof Document) {
            $resourceType = 'document';
            $resourceId = $document->getKey();
        }

        $this->auditEventService->denied(
            $request,
            AuditEventType::AuthAuthorizationDenied,
            $user,
            [
                'type' => $resourceType,
                'id' => $resourceId,
                'documentId' => $document?->getKey(),
                'revisionId' => $revision?->getKey(),
            ],
            [
                'action' => $action,
                'currentRole' => $user?->role?->value,
            ],
        );

        return response()->json([
            'message' => 'Forbidden.',
            'error' => [
                'code' => 'forbidden_document_action',
                'action' => $action,
                'currentRole' => $user?->role?->value,
            ],
        ], 403);
    }

    public function ownsRevision(User $user, DocumentRevision $revision): bool
    {
        return (int) $revision->created_by_user_id === (int) $user->getKey();
    }

    public function isCurrentRevision(Document $document, DocumentRevision $revision): bool
    {
        return (int) $document->current_revision_id === (int) $revision->getKey();
    }
}
