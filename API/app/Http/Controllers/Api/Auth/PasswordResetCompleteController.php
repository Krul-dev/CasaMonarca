<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class PasswordResetCompleteController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ]);

        $email = mb_strtolower(trim((string) $validated['email']));
        $rateLimitKey = sprintf('password-reset:complete:%s:%s', (string) $request->ip(), hash('sha256', $email));

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            return $this->fail($request, $email, 'rate_limited', 'Too many password reset attempts. Please retry later.', 429);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        $tokenRecord = DB::table('password_reset_tokens')
            ->where('email', $email)
            ->first();

        if (! $user instanceof User || $tokenRecord === null) {
            RateLimiter::hit($rateLimitKey, 300);

            return $this->fail($request, $email, 'invalid_or_expired_token', 'Password reset token is invalid or expired.', 422);
        }

        try {
            $createdAt = is_string($tokenRecord->created_at ?? null)
                ? CarbonImmutable::parse($tokenRecord->created_at)
                : null;
        } catch (\Throwable) {
            $createdAt = null;
        }

        if (
            $createdAt === null ||
            $createdAt->addHour()->isPast() ||
            ! Hash::check((string) $validated['token'], (string) $tokenRecord->token)
        ) {
            RateLimiter::hit($rateLimitKey, 300);

            return $this->fail($request, $email, 'invalid_or_expired_token', 'Password reset token is invalid or expired.', 422, $user);
        }

        DB::transaction(function () use ($email, $user, $validated): void {
            $user->forceFill([
                'password' => (string) $validated['password'],
                'remember_token' => null,
            ])->save();

            DB::table('password_reset_tokens')
                ->where('email', $email)
                ->delete();
        });

        RateLimiter::clear($rateLimitKey);

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthPasswordResetCompleted,
            $user,
            [
                'type' => 'user',
                'id' => $user->getKey(),
            ],
            [
                'method' => 'admin_issued_token',
            ],
        );

        return response()->json([
            'message' => 'Password reset successfully. You can now sign in with the new password.',
        ]);
    }

    private function fail(
        Request $request,
        string $email,
        string $reason,
        string $message,
        int $status,
        ?User $user = null,
    ): JsonResponse {
        $this->auditEventService->failure(
            $request,
            AuditEventType::AuthPasswordResetFailed,
            $user,
            $user instanceof User ? [
                'type' => 'user',
                'id' => $user->getKey(),
            ] : [],
            [
                'reason' => $reason,
                'targetEmailHash' => hash('sha256', $email),
            ],
        );

        return response()->json([
            'message' => $message,
            'error' => [
                'code' => $reason,
            ],
        ], $status);
    }
}
