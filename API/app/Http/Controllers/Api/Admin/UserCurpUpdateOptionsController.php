<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Support\Curp;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class UserCurpUpdateOptionsController extends Controller
{
    public const INTENT_KEY = 'admin.users.curp_change.webauthn.intent';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, User $user): JsonResponse
    {
        if (is_string($request->input('curp'))) {
            $request->merge(['curp' => Curp::normalize($request->input('curp'))]);
        }

        $validated = $request->validate([
            'curp' => ['present', 'nullable', 'string', 'max:18'],
        ]);
        $targetCurp = Curp::normalize($validated['curp']);

        if ($targetCurp !== null && ! Curp::isValid($targetCurp)) {
            throw ValidationException::withMessages([
                'curp' => ['The CURP format or check digit is invalid.'],
            ]);
        }

        if ($targetCurp !== null && User::query()->where('curp', $targetCurp)->where('id', '!=', $user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'curp' => ['This CURP is already assigned to another account.'],
            ]);
        }

        /** @var User $actor */
        $actor = $request->user();
        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null) {
            return response()->json(['message' => 'WebAuthn CURP-update origin is invalid.'], 422);
        }

        if ($this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'CURP updates require localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for CURP updates.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $intent = [
            'version' => 1,
            'purpose' => 'admin-user-curp-change',
            'actorUserId' => (int) $actor->getKey(),
            'targetUserId' => (int) $user->getKey(),
            'previousCurp' => $user->curp,
            'targetCurp' => $targetCurp,
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];

        $request->session()->put(self::INTENT_KEY, $intent);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AdminUserCurpChangeChallengeStarted,
            $actor,
            ['type' => 'user', 'id' => $user->getKey()],
            $this->auditMetadata($user, $user->curp, $targetCurp, ['rpId' => $originHost]),
        );

        return response()->json([
            'message' => 'CURP update authentication challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rpId' => $originHost,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials
                    ->map(fn ($credential) => [
                        'id' => $credential->credential_id,
                        'type' => 'public-key',
                        'transports' => $credential->transports,
                    ])
                    ->values(),
            ],
            'curpUpdate' => [
                'targetUserId' => $user->getKey(),
                'previousCurp' => $user->curp,
                'targetCurp' => $targetCurp,
                'expiresAt' => $expiresAt->toIso8601String(),
            ],
        ]);
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
}
