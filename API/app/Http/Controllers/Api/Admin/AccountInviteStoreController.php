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

class AccountInviteStoreController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', Rule::in($this->allowedInviteRoleValues())],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $targetRole = UserRole::from((string) $validated['role']);

        $coordinatorAllowedRoles = [
            UserRole::NonCoordinator,
            UserRole::Volunteer,
        ];

        if ($actor->role === UserRole::Coordinator && ! in_array($targetRole, $coordinatorAllowedRoles, true)) {
            $this->auditEventService->denied(
                $request,
                AuditEventType::AccountInviteCreationDenied,
                $actor,
                ['type' => 'account_invite'],
                [
                    'actorRole' => $actor->role?->value,
                    'attemptedRole' => $targetRole->value,
                    'reason' => 'coordinator_can_only_invite_non_coordinator_or_volunteer',
                ],
            );

            return response()->json([
                'message' => 'Forbidden.',
                'error' => [
                    'code' => 'forbidden_invite_role',
                    'allowedRolesToInvite' => array_map(
                        fn (UserRole $role): string => $role->value,
                        $coordinatorAllowedRoles,
                    ),
                    'attemptedRole' => $targetRole->value,
                ],
            ], 403);
        }

        $placeholderToken = $this->generateRegistrationToken();

        $invite = AccountInvite::query()->create([
            'email' => mb_strtolower(trim((string) $validated['email'])),
            'role' => $targetRole->value,
            'invited_by_user_id' => $actor->getKey(),
            'token_hash' => hash('sha256', $placeholderToken),
            'expires_at' => now('UTC')->addHours(24),
        ]);

        $this->auditEventService->success(
            $request,
            AuditEventType::AccountInviteCreated,
            $actor,
            [
                'type' => 'account_invite',
                'id' => $invite->getKey(),
            ],
            [
                'targetRole' => $targetRole->value,
                'targetEmailHash' => hash('sha256', $invite->email),
                'expiresAt' => $invite->expires_at?->toIso8601String(),
                ...($targetRole === UserRole::Admin ? [
                    'temporaryDevOnly' => true,
                ] : []),
            ],
        );

        return response()->json([
            'message' => $targetRole === UserRole::Admin
                ? 'Temporary dev-only admin invite draft created successfully.'
                : 'Invite draft created successfully.',
            'invite' => [
                'id' => $invite->getKey(),
                'email' => $invite->email,
                'role' => $invite->role?->value,
                'status' => $invite->statusLabel(),
                'expiresAt' => $invite->expires_at?->toIso8601String(),
                'createdAt' => $invite->created_at?->toIso8601String(),
                'invitedBy' => [
                    'id' => $actor->getKey(),
                    'email' => $actor->email,
                    'role' => $actor->role?->value,
                ],
                'verificationRequired' => true,
            ],
        ], 201);
    }

    /**
     * @return list<string>
     */
    private function allowedInviteRoleValues(): array
    {
        $roles = [
            UserRole::Coordinator,
            UserRole::NonCoordinator,
            UserRole::Volunteer,
        ];

        if ($this->allowsTemporaryAdminInvites()) {
            array_unshift($roles, UserRole::Admin);
        }

        return array_map(
            fn (UserRole $role): string => $role->value,
            $roles,
        );
    }

    private function allowsTemporaryAdminInvites(): bool
    {
        $configuredFlag = config('app.temporary_dev_admin_invites');

        if ($configuredFlag !== null) {
            return (bool) $configuredFlag;
        }

        return app()->environment(['local', 'dev', 'development']);
    }

    private function generateRegistrationToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
