<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;

class SessionCapabilityService
{
    public function __construct(
        private readonly SecurityEnrollmentService $securityEnrollmentService,
    ) {}

    /**
     * @return array{
     *     modules: array{
     *         admin: bool,
     *         dashboard: bool,
     *         documents: bool,
     *         history: bool,
     *         invites: bool,
     *         logging: bool,
     *         upload: bool,
     *     },
     *     security: array{
     *         enrolled: array{
     *             passkey: bool,
     *             totp: bool,
     *         },
     *         enforced: bool,
     *         isFullyEnrolled: bool,
     *         missing: array{
     *             passkey: bool,
     *             totp: bool,
     *         },
     *         requires: array{
     *             passkey: bool,
     *             totp: bool,
     *         },
     *     },
     * }
     */
    public function forUser(User $user): array
    {
        $role = $user->role ?? UserRole::default();
        $security = $this->securityEnrollmentService->summaryForUser($user);
        $blockedByEnrollment = $security['enforced'] && ! $security['isFullyEnrolled'];

        return [
            'modules' => [
                'admin' => ! $blockedByEnrollment && $role === UserRole::Admin,
                'dashboard' => true,
                'documents' => ! $blockedByEnrollment && in_array($role, [
                    UserRole::Admin,
                    UserRole::Coordinator,
                    UserRole::NonCoordinator,
                ], true),
                'history' => ! $blockedByEnrollment && in_array($role, [
                    UserRole::Admin,
                    UserRole::Coordinator,
                ], true),
                'invites' => ! $blockedByEnrollment && in_array($role, [
                    UserRole::Admin,
                    UserRole::Coordinator,
                ], true),
                'logging' => ! $blockedByEnrollment && $role === UserRole::Admin,
                'upload' => ! $blockedByEnrollment,
            ],
            'security' => $security,
        ];
    }
}
