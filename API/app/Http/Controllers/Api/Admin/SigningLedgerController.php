<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\DocumentRevision;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SigningLedgerController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $signers = User::query()
            ->whereIn('role', [
                UserRole::Admin->value,
                UserRole::Coordinator->value,
            ])
            ->with([
                'documentSignatures' => fn ($query) => $query
                    ->with(['documentRevision.document'])
                    ->latest('signed_at')
                    ->latest('id'),
            ])
            ->withCount('documentSignatures')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return response()->json([
            'message' => 'Signing ledger loaded successfully.',
            'signers' => $signers
                ->map(fn (User $signer): array => [
                    'id' => $signer->id,
                    'name' => $signer->name,
                    'email' => $signer->email,
                    'role' => $signer->role?->value,
                    'signatureCount' => (int) $signer->document_signatures_count,
                    'documents' => $this->serializeDocuments($signer),
                ])
                ->values(),
            'documents' => $this->serializeAllDocuments(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeAllDocuments(): array
    {
        return Document::query()
            ->with([
                'revisions' => fn ($query) => $query
                    ->with(['signatures.signedBy'])
                    ->orderByDesc('revision_number')
                    ->orderByDesc('id'),
            ])
            ->orderByDesc('updated_at')
            ->orderBy('title')
            ->get()
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'status' => $document->status,
                'revisions' => $document->revisions
                    ->map(fn (DocumentRevision $revision): array => $this->serializeRevision($revision))
                    ->values(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function serializeDocuments(User $signer): array
    {
        return $signer->documentSignatures
            ->filter(fn (DocumentSignature $signature): bool => $signature->documentRevision?->document !== null)
            ->groupBy(fn (DocumentSignature $signature): int => (int) $signature->documentRevision->document->id)
            ->map(function ($documentSignatures): array {
                /** @var DocumentSignature $firstSignature */
                $firstSignature = $documentSignatures->first();
                $document = $firstSignature->documentRevision->document;

                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'status' => $document->status,
                    'revisions' => $documentSignatures
                        ->groupBy(fn (DocumentSignature $signature): int => (int) $signature->document_revision_id)
                        ->map(function ($revisionSignatures): array {
                            /** @var DocumentSignature $firstRevisionSignature */
                            $firstRevisionSignature = $revisionSignatures->first();
                            $revision = $firstRevisionSignature->documentRevision;

                            return $this->serializeRevision($revision, $revisionSignatures->values()->all());
                        })
                        ->sortByDesc('revisionNumber')
                        ->values(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, DocumentSignature>|null  $signatures
     * @return array<string, mixed>
     */
    private function serializeRevision(DocumentRevision $revision, ?array $signatures = null): array
    {
        $signatures ??= $revision->signatures->all();

        return [
            'id' => $revision->id,
            'revisionNumber' => $revision->revision_number,
            'originalFileName' => $revision->original_file_name,
            'sha256' => $revision->sha256,
            'signatureStatus' => $revision->signature_status,
            'signatures' => collect($signatures)
                ->map(fn (DocumentSignature $signature): array => $this->serializeSignature($signature))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSignature(DocumentSignature $signature): array
    {
        return [
            'id' => $signature->id,
            'signatureType' => $signature->signature_type,
            'verificationStatus' => $signature->verification_status,
            'signedAt' => $signature->signed_at?->toISOString(),
            'expiresAt' => data_get($signature->metadata, 'validity.expiresAt'),
            'signatureHash' => $signature->signature_hash,
            'credential' => [
                'id' => data_get($signature->metadata, 'credentialId'),
                'name' => data_get($signature->metadata, 'credentialName'),
                'publicKeyFingerprintSha256' => data_get($signature->metadata, 'publicKeyFingerprintSha256'),
            ],
            'signedBy' => $signature->signedBy ? [
                'id' => $signature->signedBy->id,
                'name' => $signature->signedBy->name,
                'email' => $signature->signedBy->email,
                'role' => $signature->signedBy->role?->value,
            ] : null,
        ];
    }
}
