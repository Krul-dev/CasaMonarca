<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AccountInvite;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountInviteRevokeController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request, AccountInvite $invite): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $this->canManageInvite($actor, $invite)) {
            $this->auditEventService->denied(
                $request,
                AuditEventType::AccountInviteRevocationDenied,
                $actor,
                ['type' => 'account_invite', 'id' => $invite->getKey()],
                [
                    'actorRole' => $actor->role?->value,
                    'inviteRole' => $invite->role?->value,
                    'reason' => 'actor_cannot_revoke_this_invite',
                ],
            );

            return response()->json([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_invite_access',
                    'inviteRole' => $invite->role?->value,
                ],
            ], 403);
        }

        if ($invite->used_at !== null) {
            return response()->json([
                'message' => 'Redeemed invites cannot be revoked.',
                'error' => [
                    'code' => 'invite_already_redeemed',
                ],
            ], 409);
        }

        if ($invite->revoked_at === null) {
            $invite->forceFill([
                'revoked_at' => now('UTC'),
            ])->save();

            $this->auditEventService->success(
                $request,
                AuditEventType::AccountInviteRevoked,
                $actor,
                ['type' => 'account_invite', 'id' => $invite->getKey()],
                [
                    'targetRole' => $invite->role?->value,
                ],
            );
        }

        $invite->refresh();

        return response()->json([
            'message' => 'Invite revoked successfully.',
            'invite' => [
                'id' => $invite->getKey(),
                'email' => $invite->email,
                'role' => $invite->role?->value,
                'status' => $invite->statusLabel(),
                'revokedAt' => $invite->revoked_at?->toIso8601String(),
            ],
        ]);
    }

    private function canManageInvite(User $actor, AccountInvite $invite): bool
    {
        if ($actor->role === UserRole::Admin) {
            return true;
        }

        if ($actor->role === UserRole::Coordinator) {
            return in_array($invite->role, [UserRole::NonCoordinator, UserRole::Volunteer], true)
                && (int) $invite->invited_by_user_id === (int) $actor->getKey();
        }

        return false;
    }
}
