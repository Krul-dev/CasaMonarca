<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LogoutController extends Controller
{
    private const PENDING_TOTP_USER_KEY = 'auth.pending_totp_user_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user instanceof User) {
            $this->auditEventService->success(
                $request,
                AuditEventType::AuthLogout,
                $user,
            );
        }

        $request->session()->forget(self::PENDING_TOTP_USER_KEY);
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Logout successful.',
        ]);
    }
}
