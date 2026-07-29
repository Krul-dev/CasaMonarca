<?php

namespace App\Services\Registry;

use App\Enums\UserRole;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryDocument;
use App\Models\User;

class MigrantRegistryDocumentAccessService
{
    public function canDownload(User $actor, MigrantRegistryDocument $document): bool
    {
        if (in_array($actor->role ?? UserRole::default(), [UserRole::Admin, UserRole::Coordinator], true)) {
            return true;
        }

        return $actor->role === UserRole::NonCoordinator
            && $this->isCoveredByCompletedAccess($document);
    }

    public function isCoveredByCompletedAccess(MigrantRegistryDocument $document): bool
    {
        if ($document->created_at === null) {
            return false;
        }

        return MigrantArcoRequest::query()
            ->where('registry_entry_id', $document->registry_entry_id)
            ->where('request_type', 'access')
            ->where('status', MigrantArcoService::STATUS_COMPLETED)
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $document->created_at)
            ->exists();
    }
}
