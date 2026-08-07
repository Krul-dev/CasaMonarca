<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Admin\UserDirectoryViewService;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\WebauthnAssertionService;
use App\Support\Curp;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserCurpUpdateVerifyController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly UserDirectoryViewService $userDirectoryViewService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, User $user): JsonResponse
    {
        $intent = $request->session()->get(UserCurpUpdateOptionsController::INTENT_KEY);

        if (! $this->isValidIntent($intent)) {
            return response()->json(['message' => 'CURP update authentication challenge was not initiated.'], 401);
        }

        try {
            $expiresAt = CarbonImmutable::parse((string) $intent['expiresAt']);
        } catch (\Throwable) {
            return response()->json(['message' => 'CURP update authentication challenge is invalid.'], 401);
        }

        if ($expiresAt->isPast()) {
            $this->forgetChallenge($request);

            return response()->json(['message' => 'CURP update authentication challenge expired. Request a new challenge.'], 401);
        }

        /** @var User|null $actor */
        $actor = $request->user();

        if ($actor === null || (int) $actor->getKey() !== (int) $intent['actorUserId']) {
            return response()->json(['message' => 'CURP update challenge does not match the authenticated session.'], 401);
        }

        if ((int) $user->getKey() !== (int) $intent['targetUserId']) {
            return response()->json(['message' => 'CURP update challenge does not match the selected account.'], 401);
        }

        $previousCurp = Curp::normalize($intent['previousCurp']);
        $targetCurp = Curp::normalize($intent['targetCurp']);

        if ($targetCurp !== null && ! Curp::isValid($targetCurp)) {
            $this->forgetChallenge($request);

            return $this->denied($request, $actor, $user, $previousCurp, $targetCurp, 'invalid_target_curp', 422);
        }

        if ($user->curp !== $previousCurp) {
            $this->forgetChallenge($request);

            return $this->denied($request, $actor, $user, $previousCurp, $targetCurp, 'curp_changed', 409);
        }

        if ($targetCurp !== null && User::query()->where('curp', $targetCurp)->where('id', '!=', $user->getKey())->exists()) {
            $this->forgetChallenge($request);

            return $this->denied($request, $actor, $user, $previousCurp, $targetCurp, 'duplicate_curp', 422);
        }

        $payload = $request->validate([
            'id' => ['required', 'string'],
            'rawId' => ['required', 'string'],
            'type' => ['required', 'string', 'in:public-key'],
            'response' => ['required', 'array'],
            'response.clientDataJSON' => ['required', 'string'],
            'response.authenticatorData' => ['required', 'string'],
            'response.signature' => ['required', 'string'],
            'response.userHandle' => ['nullable', 'string'],
        ]);
        $credential = $actor->webauthnCredentials()->where('credential_id', (string) $payload['id'])->first();

        if (! $credential instanceof WebauthnCredential) {
            throw ValidationException::withMessages([
                'id' => ['This security key is not registered to the current admin account.'],
            ]);
        }

        $newSignCount = $this->webauthnAssertionService->verifyAssertionPayload(
            $payload,
            $credential,
            (string) $intent['challenge'],
            (string) $intent['origin'],
            (string) $intent['rpId'],
        );

        $credential->forceFill(['sign_count' => $newSignCount, 'last_used_at' => now()])->save();

        try {
            $user->forceFill(['curp' => $targetCurp])->save();
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            $this->forgetChallenge($request);

            return $this->denied($request, $actor, $user, $previousCurp, $targetCurp, 'duplicate_curp', 422);
        }

        $this->forgetChallenge($request);
        $this->auditEventService->success(
            $request,
            AuditEventType::AdminUserCurpChanged,
            $actor,
            ['type' => 'user', 'id' => $user->getKey()],
            $this->auditMetadata($user, $previousCurp, $targetCurp, [
                'credentialIdPreview' => substr((string) $credential->credential_id, 0, 16),
                'signCount' => $newSignCount,
            ]),
        );

        return response()->json([
            'message' => 'User CURP updated successfully.',
            'user' => $this->userDirectoryViewService->serialize($user->fresh() ?? $user),
        ]);
    }

    private function isValidIntent(mixed $intent): bool
    {
        return is_array($intent)
            && (int) ($intent['version'] ?? 0) === 1
            && ($intent['purpose'] ?? null) === 'admin-user-curp-change'
            && is_numeric($intent['actorUserId'] ?? null)
            && is_numeric($intent['targetUserId'] ?? null)
            && array_key_exists('previousCurp', $intent)
            && ($intent['previousCurp'] === null || is_string($intent['previousCurp']))
            && array_key_exists('targetCurp', $intent)
            && ($intent['targetCurp'] === null || is_string($intent['targetCurp']))
            && is_string($intent['challenge'] ?? null)
            && is_string($intent['origin'] ?? null)
            && is_string($intent['rpId'] ?? null)
            && is_string($intent['expiresAt'] ?? null);
    }

    private function denied(Request $request, User $actor, User $user, ?string $previousCurp, ?string $targetCurp, string $reason, int $status): JsonResponse
    {
        $this->auditEventService->denied(
            $request,
            AuditEventType::AdminUserCurpChanged,
            $actor,
            ['type' => 'user', 'id' => $user->getKey()],
            $this->auditMetadata($user, $previousCurp, $targetCurp, ['reason' => $reason]),
        );

        return response()->json([
            'message' => match ($reason) {
                'duplicate_curp' => 'This CURP is already assigned to another account.',
                'curp_changed' => 'The account CURP changed after authentication started. Refresh and try again.',
                default => 'The requested CURP is invalid.',
            },
            'error' => ['code' => $reason],
        ], $status);
    }

    /** @return array<string, mixed> */
    private function auditMetadata(User $user, ?string $previousCurp, ?string $targetCurp, array $metadata = []): array
    {
        return [
            'targetUserId' => $user->getKey(),
            'targetUserName' => $user->name,
            'targetUserEmail' => $user->email,
            'previousCurpPresent' => $previousCurp !== null,
            'targetCurpPresent' => $targetCurp !== null,
            ...$metadata,
        ];
    }

    private function forgetChallenge(Request $request): void
    {
        $request->session()->forget(UserCurpUpdateOptionsController::INTENT_KEY);
        $request->session()->regenerateToken();
    }
}
