<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantArcoChallengeService;
use App\Services\Registry\MigrantArcoService;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MigrantArcoCreateVerifyController extends Controller
{
    public function __construct(private readonly MigrantArcoChallengeService $challenges, private readonly MigrantArcoService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! in_array($actor->role, [UserRole::NonCoordinator, UserRole::Coordinator, UserRole::Admin], true)) {
            abort(403);
        }
        $intent = $this->challenges->intent($request, 'create');
        abort_unless(in_array($intent['requestType'] ?? null, config('features.arco_types', ['access']), true), 404);

        $entry = MigrantRegistryEntry::query()->findOrFail((int) ($intent['entryId'] ?? 0));
        if ($entry->current_status !== MigrantRegistryService::STATUS_APPROVED || ! hash_equals((string) ($intent['originalPayloadHash'] ?? ''), $this->service->payloadHash($entry->payload_json))) {
            abort(409, 'The registration changed after the ARCO signature challenge started.');
        }
        $signature = $this->challenges->verify($request, $actor, 'create');
        $arco = $this->service->create($actor, $entry, (string) $intent['requestType'], (string) $intent['reason'], is_array($intent['proposedPayload'] ?? null) ? $intent['proposedPayload'] : null, $signature);

        return response()->json(['message' => 'ARCO request signed and submitted for coordinator review.', 'data' => $arco], 201);
    }
}
