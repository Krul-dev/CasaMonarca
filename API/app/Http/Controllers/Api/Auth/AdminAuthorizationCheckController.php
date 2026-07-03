<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuthorizationCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'message' => 'Admin authorization check passed.',
            'user' => [
                'id' => $user->getKey(),
                'email' => $user->email,
                'role' => $user->role?->value,
            ],
        ]);
    }
}
