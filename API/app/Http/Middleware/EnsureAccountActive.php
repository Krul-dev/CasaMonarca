<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountActive
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null || $user->isActiveAccount()) {
            return $next($request);
        }

        $this->auditEventService->denied(
            $request,
            AuditEventType::AuthAuthorizationDenied,
            $user,
            metadata: [
                'reason' => 'account_suspended',
            ],
        );

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'This account is suspended.',
            'error' => [
                'code' => 'account_suspended',
            ],
        ], 403);
    }
}
