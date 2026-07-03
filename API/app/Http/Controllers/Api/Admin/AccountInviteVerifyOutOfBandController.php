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
use Illuminate\Validation\Rule;

class AccountInviteVerifyOutOfBandController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request, AccountInvite $invite): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', Rule::in(['phone', 'in_person'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        if (! $this->canManageInvite($actor, $invite)) {
            $this->auditEventService->denied(
                $request,
                AuditEventType::AccountInviteVerificationDenied,
                $actor,
                ['type' => 'account_invite', 'id' => $invite->getKey()],
                [
                    'actorRole' => $actor->role?->value,
                    'inviteRole' => $invite->role?->value,
                    'reason' => 'actor_cannot_verify_this_invite',
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
                'message' => 'Invite can no longer be verified.',
                'error' => [
                    'code' => 'invite_not_active',
                ],
            ], 409);
        }

        $invite->forceFill([
            'verified_out_of_band_at' => now('UTC'),
            'verified_out_of_band_by_user_id' => $actor->getKey(),
            'verification_method' => (string) $validated['method'],
            'verification_note' => isset($validated['note']) ? trim((string) $validated['note']) : null,
        ])->save();

        $invite->refresh();

        $this->auditEventService->success(
            $request,
            AuditEventType::AccountInviteVerified,
            $actor,
            ['type' => 'account_invite', 'id' => $invite->getKey()],
            [
                'targetRole' => $invite->role?->value,
                'method' => $invite->verification_method,
            ],
        );

        return response()->json([
            'message' => 'Out-of-band verification recorded successfully.',
            'invite' => [
                'id' => $invite->getKey(),
                'email' => $invite->email,
                'role' => $invite->role?->value,
                'status' => $invite->statusLabel(),
                'verifiedOutOfBandAt' => $invite->verified_out_of_band_at?->toIso8601String(),
                'verificationMethod' => $invite->verification_method,
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
