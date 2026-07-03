<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebauthnCredential;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\BrowserDeviceService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebauthnLoginOptionsController extends Controller
{
    private const LOGIN_CHALLENGE_KEY = 'auth.webauthn.login_challenge';

    private const LOGIN_ORIGIN_KEY = 'auth.webauthn.login_origin';

    private const LOGIN_RP_ID_KEY = 'auth.webauthn.login_rp_id';

    private const LOGIN_USER_ID_KEY = 'auth.webauthn.login_user_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly BrowserDeviceService $browserDeviceService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'email' => ['nullable', 'email'],
        ]);

        $origin = $this->resolveRequestOrigin($request);
        $originHost = parse_url($origin, PHP_URL_HOST);

        if (! is_string($originHost) || $originHost === '') {
            return response()->json([
                'message' => 'WebAuthn login origin is invalid.',
            ], 422);
        }

        if ($this->isIpHost($originHost)) {
            return response()->json([
                'message' => 'WebAuthn login requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $email = is_string($payload['email'] ?? null)
            ? strtolower((string) $payload['email'])
            : null;

        $user = null;
        $credentialsQuery = WebauthnCredential::query();

        if ($email !== null) {
            /** @var User|null $user */
            $user = User::query()
                ->where('email', $email)
                ->first();

            if ($user === null) {
                return response()->json([
                    'message' => 'No security keys were found for this account.',
                ], 422);
            }

            $credentialsQuery->where('user_id', $user->getKey());
        }

        $credentials = $credentialsQuery->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'No registered security keys are available for sign-in.',
            ], 422);
        }

        $challenge = $this->base64UrlService->encode(random_bytes(32));

        $request->session()->put(self::LOGIN_CHALLENGE_KEY, $challenge);
        $request->session()->put(self::LOGIN_ORIGIN_KEY, $origin);
        $request->session()->put(self::LOGIN_RP_ID_KEY, $originHost);
        $request->session()->put(self::LOGIN_USER_ID_KEY, $user?->getKey());
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthPasskeyLoginChallengeStarted,
            $user,
            [
                'type' => 'session',
            ],
            [
                'allowCredentialCount' => $credentials->count(),
                ...$this->browserDeviceService->existingDeviceCookieMetadata($request),
                'method' => 'passkey',
                'rpId' => $originHost,
                'userScoped' => $user instanceof User,
            ],
        );

        return response()->json([
            'message' => 'WebAuthn login challenge created.',
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
        ]);
    }

    private function resolveRequestOrigin(Request $request): string
    {
        $headerOrigin = (string) $request->headers->get('origin', '');

        return $headerOrigin !== ''
            ? $headerOrigin
            : $request->getSchemeAndHttpHost();
    }

    private function isIpHost(string $host): bool
    {
        return filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false;
    }
}
