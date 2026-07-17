<?php

namespace App\Http\Controllers\Api\Registry;

use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantRegistryReviewReturnController extends Controller
{
    public function __construct(
        private readonly MigrantRegistryService $migrantRegistryService,
    ) {}

    public function __invoke(Request $request, MigrantRegistryEntry $migrantRegistryEntry): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var User|null $actor */
        $actor = $request->user();

        if (! $actor instanceof User) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($migrantRegistryEntry->current_status !== MigrantRegistryService::STATUS_PENDING_REVIEW) {
            return response()->json(['message' => 'This migrant registration is no longer pending review.'], 409);
        }

        return response()->json([
            'message' => 'Migrant registration returned for corrections.',
            'data' => $this->migrantRegistryService->returnForCorrections(
                $actor,
                $migrantRegistryEntry,
                trim((string) $validated['reason']),
            ),
        ]);
    }
}
