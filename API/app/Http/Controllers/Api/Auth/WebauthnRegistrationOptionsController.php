<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\Base64UrlService;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebauthnRegistrationOptionsController extends Controller
{
    private const REGISTRATION_CHALLENGE_KEY = 'auth.webauthn.registration_challenge';

    private const REGISTRATION_USER_KEY = 'auth.webauthn.registration_user_id';

    private const REGISTRATION_ORIGIN_KEY = 'auth.webauthn.registration_origin';

    private const REGISTRATION_RP_ID_KEY = 'auth.webauthn.registration_rp_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $origin = $this->resolveRequestOrigin($request);
        $originHost = parse_url($origin, PHP_URL_HOST);

        if (! is_string($originHost) || $originHost === '') {
            return response()->json([
                'message' => 'WebAuthn registration origin is invalid.',
            ], 422);
        }

        if ($this->isIpHost($originHost)) {
            return response()->json([
                'message' => 'WebAuthn registration requires localhost or a domain name. Use localhost instead of an IP address.',
            ], 422);
        }

        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $userId = $this->base64UrlService->encode((string) $user->getKey());

        $request->session()->put(self::REGISTRATION_CHALLENGE_KEY, $challenge);
        $request->session()->put(self::REGISTRATION_USER_KEY, $user->getKey());
        $request->session()->put(self::REGISTRATION_ORIGIN_KEY, $origin);
        $request->session()->put(self::REGISTRATION_RP_ID_KEY, $originHost);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthPasskeyRegistrationChallengeStarted,
            $user,
            [
                'type' => 'session',
            ],
            [
                'excludeCredentialCount' => $user->webauthnCredentials()->count(),
                'method' => 'passkey',
                'rpId' => $originHost,
            ],
        );

        return response()->json([
            'message' => 'WebAuthn registration challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rp' => [
                    'name' => config('app.name', 'CasaMonarca'),
                    'id' => $originHost,
                ],
                'user' => [
                    'id' => $userId,
                    'name' => $user->email,
                    'displayName' => $user->name,
                ],
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257],
                ],
                'timeout' => 60000,
                'attestation' => 'none',
                'authenticatorSelection' => [
                    'authenticatorAttachment' => 'cross-platform',
                    'residentKey' => 'preferred',
                    'userVerification' => 'preferred',
                ],
                'excludeCredentials' => $user->webauthnCredentials()
                    ->get()
                    ->map(fn ($credential) => [
                        'id' => $credential->credential_id,
                        'type' => 'public-key',
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
