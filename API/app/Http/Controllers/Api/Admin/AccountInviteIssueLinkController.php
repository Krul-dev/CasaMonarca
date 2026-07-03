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

class AccountInviteIssueLinkController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request, AccountInvite $invite): JsonResponse
    {
        $validated = $request->validate([
            'expiresInHours' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if (! $this->canManageInvite($actor, $invite)) {
            $this->auditEventService->denied(
                $request,
                AuditEventType::AccountInviteLinkIssueDenied,
                $actor,
                ['type' => 'account_invite', 'id' => $invite->getKey()],
                [
                    'actorRole' => $actor->role?->value,
                    'inviteRole' => $invite->role?->value,
                    'reason' => 'actor_cannot_issue_link_for_this_invite',
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

        if ($invite->used_at !== null || $invite->revoked_at !== null) {
            return response()->json([
                'message' => 'Invite can no longer issue links.',
                'error' => [
                    'code' => 'invite_not_active',
                ],
            ], 409);
        }

        if ($invite->verified_out_of_band_at === null) {
            return response()->json([
                'message' => 'Out-of-band verification is required before issuing the invite link.',
                'error' => [
                    'code' => 'invite_not_verified',
                ],
            ], 409);
        }

        if ($invite->issued_at !== null && $invite->expires_at !== null && $invite->expires_at->isFuture()) {
            return response()->json([
                'message' => 'An active invite link already exists for this record.',
                'error' => [
                    'code' => 'invite_link_already_issued',
                ],
            ], 409);
        }

        $token = $this->generateRegistrationToken();
        $expiresInHours = (int) ($validated['expiresInHours'] ?? 24);

        $invite->forceFill([
            'token_hash' => hash('sha256', $token),
            'issued_at' => now('UTC'),
            'expires_at' => now('UTC')->addHours($expiresInHours),
        ])->save();

        $invite->refresh();

        $this->auditEventService->success(
            $request,
            AuditEventType::AccountInviteLinkIssued,
            $actor,
            ['type' => 'account_invite', 'id' => $invite->getKey()],
            [
                'targetRole' => $invite->role?->value,
                'expiresAt' => $invite->expires_at?->toIso8601String(),
            ],
        );

        return response()->json([
            'message' => 'Invite link issued successfully.',
            'invite' => [
                'id' => $invite->getKey(),
                'email' => $invite->email,
                'role' => $invite->role?->value,
                'status' => $invite->statusLabel(),
                'issuedAt' => $invite->issued_at?->toIso8601String(),
                'expiresAt' => $invite->expires_at?->toIso8601String(),
                'registrationToken' => $token,
                'registrationPath' => '/register?inviteToken='.$token,
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

    private function generateRegistrationToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
