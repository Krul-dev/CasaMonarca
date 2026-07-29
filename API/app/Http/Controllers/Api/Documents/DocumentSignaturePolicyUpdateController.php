<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Documents\DocumentSignaturePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentSignaturePolicyUpdateController extends Controller
{
    public function __construct(
        private readonly DocumentSignaturePolicyService $documentSignaturePolicyService,
    ) {}

    public function __invoke(
        Request $request,
        Document $document,
        DocumentRevision $revision,
    ): JsonResponse {
        abort_unless(
            (int) $revision->document_id === (int) $document->getKey(),
            404,
            'Selected document revision could not be found.',
        );

        $validated = $request->validate([
            'expectedVersion' => ['required', 'integer', 'min:1'],
            'signatureOrderEnforced' => ['required', 'boolean'],
            'requirements' => ['present', 'array', 'max:20'],
            'requirements.*.id' => ['nullable', 'integer', 'min:1'],
            'requirements.*.type' => ['required', 'string', 'in:role,user'],
            'requirements.*.role' => ['nullable', 'string'],
            'requirements.*.userId' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $policy = $this->documentSignaturePolicyService->update(
            $request,
            $actor,
            $document,
            $revision,
            (int) $validated['expectedVersion'],
            (bool) $validated['signatureOrderEnforced'],
            $validated['requirements'],
        );

        return response()->json([
            'message' => 'Document revision signature policy updated successfully.',
            'revision' => [
                'id' => (int) $revision->getKey(),
                'signatureStatus' => $revision->fresh()?->signature_status,
                'signaturePolicy' => $policy,
            ],
        ]);
    }
}
