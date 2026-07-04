<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AccountInvite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountInviteIndexController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $limit = (int) ($validated['limit'] ?? 25);

        $query = AccountInvite::query()
            ->with(['invitedBy:id,name,email,role', 'verifiedOutOfBandBy:id,name,email,role'])
            ->latest('id');

        if ($actor->role === UserRole::Coordinator) {
            $query->where('invited_by_user_id', $actor->getKey())
                ->whereIn('role', [
                    UserRole::NonCoordinator->value,
                    UserRole::Volunteer->value,
                ]);
        }

        $invites = $query->limit($limit)->get();

        return response()->json([
            'message' => 'Invites loaded successfully.',
            'invites' => $invites->map(fn (AccountInvite $invite): array => [
                'id' => $invite->getKey(),
                'email' => $invite->email,
                'role' => $invite->role?->value,
                'status' => $invite->statusLabel(),
                'createdAt' => $invite->created_at?->toIso8601String(),
                'issuedAt' => $invite->issued_at?->toIso8601String(),
                'expiresAt' => $invite->expires_at?->toIso8601String(),
                'verifiedOutOfBandAt' => $invite->verified_out_of_band_at?->toIso8601String(),
                'verificationMethod' => $invite->verification_method,
                'usedAt' => $invite->used_at?->toIso8601String(),
                'revokedAt' => $invite->revoked_at?->toIso8601String(),
                'invitedBy' => [
                    'id' => $invite->invitedBy?->getKey(),
                    'email' => $invite->invitedBy?->email,
                    'role' => $invite->invitedBy?->role?->value,
                ],
                'verifiedBy' => [
                    'id' => $invite->verifiedOutOfBandBy?->getKey(),
                    'email' => $invite->verifiedOutOfBandBy?->email,
                    'role' => $invite->verifiedOutOfBandBy?->role?->value,
                ],
            ])->values()->all(),
        ]);
    }
}
