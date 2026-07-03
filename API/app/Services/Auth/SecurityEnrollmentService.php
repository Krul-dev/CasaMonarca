<?php

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;

class SecurityEnrollmentService
{
    /**
     * @return array{
     *     enrolled: array{
     *         passkey: bool,
     *         totp: bool,
     *     },
     *     enforced: bool,
     *     isFullyEnrolled: bool,
     *     missing: array{
     *         passkey: bool,
     *         totp: bool,
     *     },
     *     requires: array{
     *         passkey: bool,
     *         totp: bool,
     *     },
     * }
     */
    public function summaryForUser(User $user): array
    {
        $role = $user->role ?? UserRole::default();
        $requiresTotp = in_array($role, [
            UserRole::Admin,
            UserRole::Coordinator,
            UserRole::NonCoordinator,
            UserRole::Volunteer,
        ], true);
        $requiresPasskey = in_array($role, [
            UserRole::Admin,
            UserRole::Coordinator,
        ], true);
        $hasTotp = $user->hasTotpEnabled();
        $hasPasskey = $user->webauthnCredentials()->exists();
        $missingTotp = $requiresTotp && ! $hasTotp;
        $missingPasskey = $requiresPasskey && ! $hasPasskey;

        return [
            'requires' => [
                'totp' => $requiresTotp,
                'passkey' => $requiresPasskey,
            ],
            'enrolled' => [
                'totp' => $hasTotp,
                'passkey' => $hasPasskey,
            ],
            'missing' => [
                'totp' => $missingTotp,
                'passkey' => $missingPasskey,
            ],
            'enforced' => $requiresTotp || $requiresPasskey,
            'isFullyEnrolled' => ! $missingTotp && ! $missingPasskey,
        ];
    }
}
