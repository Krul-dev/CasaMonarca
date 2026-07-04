<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AccountInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountInvitePreviewController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:255'],
        ]);

        $tokenHash = hash('sha256', (string) $validated['token']);
        $invite = AccountInvite::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if (! $invite instanceof AccountInvite || $invite->statusLabel() !== 'issued') {
            return response()->json([
                'message' => 'Invite link is no longer available.',
                'error' => [
                    'code' => 'invite_unavailable',
                    'status' => $invite?->statusLabel(),
                ],
            ], 410);
        }

        $requirements = $this->enrollmentRequirements($invite->role ?? UserRole::NonCoordinator);

        return response()->json([
            'message' => 'Invite link is available.',
            'invite' => [
                'email' => $invite->email,
                'role' => $invite->role?->value,
                'status' => $invite->statusLabel(),
                'expiresAt' => $invite->expires_at?->toIso8601String(),
            ],
            'enrollment' => [
                'requiresTotp' => $requirements['requiresTotp'],
                'requiresPasskey' => $requirements['requiresPasskey'],
            ],
        ]);
    }

    /**
     * @return array{requiresTotp: bool, requiresPasskey: bool}
     */
    private function enrollmentRequirements(UserRole $role): array
    {
        return [
            'requiresTotp' => in_array($role, [UserRole::Admin, UserRole::Coordinator, UserRole::NonCoordinator, UserRole::Volunteer], true),
            'requiresPasskey' => in_array($role, [UserRole::Admin, UserRole::Coordinator], true),
        ];
    }
}
