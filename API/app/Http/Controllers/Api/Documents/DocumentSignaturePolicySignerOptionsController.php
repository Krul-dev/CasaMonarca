<?php

namespace App\Http\Controllers\Api\Documents;

use App\Http\Controllers\Controller;
use App\Services\Documents\DocumentSignaturePolicyService;
use Illuminate\Http\JsonResponse;

class DocumentSignaturePolicySignerOptionsController extends Controller
{
    public function __construct(
        private readonly DocumentSignaturePolicyService $documentSignaturePolicyService,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Document signature policy signer options loaded successfully.',
            ...$this->documentSignaturePolicyService->signerOptions(),
        ]);
    }
}
