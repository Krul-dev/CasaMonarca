<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebauthnCredentialListController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return response()->json([
            'message' => 'Registered security keys loaded.',
            'credentials' => $user->webauthnCredentials()
                ->orderByDesc('id')
                ->get()
                ->map(fn ($credential) => [
                    'id' => $credential->credential_id,
                    'name' => $credential->name,
                    'transports' => $credential->transports,
                    'createdAt' => $credential->created_at?->toISOString(),
                    'lastUsedAt' => $credential->last_used_at?->toISOString(),
                ])
                ->values(),
        ]);
    }
}
