<?php

namespace App\Http\Controllers\Api\Auth;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebauthnCredentialDeleteController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
    ) {}

    public function __invoke(Request $request, string $credentialId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $credential = $user->webauthnCredentials()
            ->where('credential_id', $credentialId)
            ->first();

        if ($credential === null) {
            return response()->json([
                'message' => 'Security key not found.',
            ], 404);
        }

        $credentialName = $credential->name;
        $credentialIdPreview = substr((string) $credential->credential_id, 0, 16);

        $credential->delete();

        $this->auditEventService->success(
            $request,
            AuditEventType::AuthPasskeyRemoved,
            $user,
            [
                'type' => 'webauthn_credential',
                'id' => null,
            ],
            [
                'credentialIdPreview' => $credentialIdPreview,
                'credentialName' => $credentialName,
            ],
        );

        return response()->json([
            'message' => 'Security key removed successfully.',
        ]);
    }
}
