<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentApprovalViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentApprovalIndexController extends Controller
{
    public function __construct(
        private readonly DocumentApprovalViewService $documentApprovalViewService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 50);

        $documents = Document::query()
            ->with([
                'uploadedBy',
                'approvedBy',
                'currentRevision',
                'signatureRequirements.signerUser',
            ])
            ->where('status', 'pending_approval')
            ->latest()
            ->limit($limit)
            ->get();

        $signers = User::query()
            ->withCount('webauthnCredentials')
            ->whereIn('role', [
                UserRole::Admin->value,
                UserRole::Coordinator->value,
            ])
            ->where('status', 'active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Document approvals loaded successfully.',
            'documents' => $documents
                ->map(fn (Document $document): array => $this->documentApprovalViewService->serialize($document))
                ->values(),
            'signingRoles' => [
                UserRole::Admin->value,
                UserRole::Coordinator->value,
            ],
            'signingUsers' => $signers
                ->map(fn (User $user): array => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role?->value,
                    'passkeyCount' => (int) ($user->webauthn_credentials_count ?? 0),
                ])
                ->values(),
        ]);
    }
}
