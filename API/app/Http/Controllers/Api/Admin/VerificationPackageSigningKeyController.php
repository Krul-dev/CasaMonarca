<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Documents\VerificationPackageSigningKeyService;
use Illuminate\Http\JsonResponse;

class VerificationPackageSigningKeyController extends Controller
{
    public function __construct(
        private readonly VerificationPackageSigningKeyService $signingKeyService,
    ) {}

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'message' => 'Verification package signing key loaded successfully.',
            'signingKey' => $this->signingKeyService->summary(),
        ]);
    }
}
