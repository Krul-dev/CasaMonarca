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

class MigrantArcoDecisionOptionsController extends Controller
{
    public function __construct(private readonly MigrantArcoChallengeService $challenges) {}

    public function coordinator(Request $request, MigrantArcoRequest $migrantArcoRequest): JsonResponse
    {
        return $this->issue($request, $migrantArcoRequest, 'coordinator-decision');
    }

    public function admin(Request $request, MigrantArcoRequest $migrantArcoRequest): JsonResponse
    {
        return $this->issue($request, $migrantArcoRequest, 'admin-decision');
    }

    private function issue(Request $request, MigrantArcoRequest $arco, string $stage): JsonResponse
    {
        abort_unless(in_array($arco->request_type, config('features.arco_types', ['access']), true), 404);

        $actor = $request->user();
        $allowed = $stage === 'admin-decision'
            ? $actor instanceof User && $actor->role === UserRole::Admin
            : $actor instanceof User && in_array($actor->role, [UserRole::Coordinator, UserRole::Admin], true);
        if (! $allowed) {
            abort(403, 'This account cannot perform this ARCO decision.');
        }
        $expected = $stage === 'admin-decision' ? MigrantArcoService::STATUS_PENDING_ADMIN : MigrantArcoService::STATUS_PENDING_COORDINATOR;
        if ($arco->status !== $expected || ($stage === 'admin-decision' && $arco->request_type !== 'cancellation')) {
            abort(409, 'This ARCO request is no longer available for this decision.');
        }
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'reason' => ['nullable', 'string', 'max:2000']]);
        $reason = trim((string) ($data['reason'] ?? '')) ?: null;
        if (($data['decision'] === 'reject' || $arco->request_type === 'opposition') && $reason === null) {
            abort(422, 'A resolution reason is required for this decision.');
        }
        $intent = ['arcoRequestId' => $arco->id, 'requestType' => $arco->request_type, 'requestStatus' => $arco->status, 'decision' => $data['decision'], 'reason' => $reason, 'stateHash' => $this->stateHash($arco)];

        return response()->json($this->challenges->issue($request, $actor, $stage, $intent, 'migrant_arco_request', $arco->id));
    }

    public static function stateHash(MigrantArcoRequest $arco): string
    {
        return hash('sha256', implode('|', [$arco->id, $arco->status, $arco->request_type, $arco->original_payload_hash, $arco->proposed_payload_hash]));
    }
}
