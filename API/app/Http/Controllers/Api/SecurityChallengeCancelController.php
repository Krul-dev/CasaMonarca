<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Services\Security\SecurityChallengeIntentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityChallengeCancelController extends Controller
{
    public function __construct(
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
    ) {}

    public function __invoke(Request $request, SecurityChallengeIntent $intent): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || (int) $intent->actor_user_id !== (int) $user->getKey()) {
            return response()->json([
                'message' => 'Challenge intent could not be found.',
            ], 404);
        }

        if (! $intent->isPending()) {
            return response()->json([
                'message' => 'Challenge intent is no longer pending.',
                'challengeIntent' => [
                    'id' => $intent->getKey(),
                    'status' => $intent->status,
                ],
            ]);
        }

        $this->securityChallengeIntentService->markCancelled($intent, $request);

        return response()->json([
            'message' => 'Challenge intent cancelled.',
            'challengeIntent' => [
                'id' => $intent->getKey(),
                'status' => SecurityChallengeIntent::STATUS_CANCELLED,
            ],
        ]);
    }
}
