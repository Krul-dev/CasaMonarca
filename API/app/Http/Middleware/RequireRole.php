<?php

namespace App\Http\Middleware;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    /**
     * @param  list<string>  $requiredRoles
     */
    public function handle(Request $request, Closure $next, string ...$requiredRoles): Response
    {
        $resolvedRequiredRoles = $this->resolveRequiredRoles($requiredRoles);

        /** @var User|null $user */
        $user = $request->user();
        $currentRole = $user?->role?->value;

        if (! is_string($currentRole) || ! in_array($currentRole, $resolvedRequiredRoles, true)) {
            return $this->forbiddenResponse($request, $resolvedRequiredRoles, $currentRole);
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $requiredRoles
     * @return list<string>
     */
    private function resolveRequiredRoles(array $requiredRoles): array
    {
        if ($requiredRoles === []) {
            throw new InvalidArgumentException('RequireRole middleware needs at least one role.');
        }

        $allowedRoles = UserRole::values();
        $resolvedRoles = [];

        foreach ($requiredRoles as $requiredRole) {
            $normalizedRole = strtolower(trim($requiredRole));

            if ($normalizedRole === '' || ! in_array($normalizedRole, $allowedRoles, true)) {
                continue;
            }

            $resolvedRoles[] = $normalizedRole;
        }

        $resolvedRoles = array_values(array_unique($resolvedRoles));

        if ($resolvedRoles === []) {
            throw new InvalidArgumentException('RequireRole middleware only accepts valid canonical roles.');
        }

        return $resolvedRoles;
    }

    /**
     * @param  list<string>  $requiredRoles
     */
    private function forbiddenResponse(Request $request, array $requiredRoles, ?string $currentRole): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        $this->auditEventService->denied(
            $request,
            AuditEventType::AuthAuthorizationDenied,
            $user,
            metadata: [
                'action' => 'require-role',
                'path' => $request->path(),
                'requiredRoles' => $requiredRoles,
                'currentRole' => $currentRole,
            ],
        );

        return response()->json([
            'message' => 'Forbidden.',
            'error' => [
                'code' => 'forbidden_role',
                'requiredRoles' => $requiredRoles,
                'currentRole' => $currentRole,
            ],
        ], 403);
    }
}
