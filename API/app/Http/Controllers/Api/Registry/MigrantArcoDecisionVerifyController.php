<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantArcoRequest;
use App\Models\User;
use App\Services\Registry\MigrantArcoChallengeService;
use App\Services\Registry\MigrantArcoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantArcoDecisionVerifyController extends Controller
{
    public function __construct(private readonly MigrantArcoChallengeService $challenges, private readonly MigrantArcoService $service) {}

    public function coordinator(Request $request, MigrantArcoRequest $migrantArcoRequest): JsonResponse
    {
        return $this->verify($request, $migrantArcoRequest, 'coordinator-decision');
    }

    public function admin(Request $request, MigrantArcoRequest $migrantArcoRequest): JsonResponse
    {
        return $this->verify($request, $migrantArcoRequest, 'admin-decision');
    }

    private function verify(Request $request, MigrantArcoRequest $arco, string $stage): JsonResponse
    {
        abort_unless(in_array($arco->request_type, config('features.arco_types', ['access']), true), 404);

        $actor = $request->user();
        $allowed = $stage === 'admin-decision'
            ? $actor instanceof User && $actor->role === UserRole::Admin
            : $actor instanceof User && in_array($actor->role, [UserRole::Coordinator, UserRole::Admin], true);
        if (! $allowed) {
            abort(403);
        }
        $intent = $this->challenges->intent($request, $stage);
        $arco->refresh();
        if ((int) ($intent['arcoRequestId'] ?? 0) !== (int) $arco->id || ! hash_equals((string) ($intent['stateHash'] ?? ''), MigrantArcoDecisionOptionsController::stateHash($arco))) {
            abort(409, 'The ARCO request changed after the signature challenge started.');
        }
        $signature = $this->challenges->verify($request, $actor, $stage);
        $resolved = $stage === 'admin-decision'
            ? $this->service->adminDecision($actor, $arco, (string) $intent['decision'], $intent['reason'] ?? null, $signature)
            : $this->service->coordinatorDecision($actor, $arco, (string) $intent['decision'], $intent['reason'] ?? null, $signature);

        return response()->json(['message' => 'ARCO decision signed and completed.', 'data' => $resolved]);
    }
}
