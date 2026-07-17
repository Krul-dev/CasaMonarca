<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMigrantRegistryRequest;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Registry\MigrantArcoChallengeService;
use App\Services\Registry\MigrantArcoService;
use App\Services\Registry\MigrantRegistryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MigrantArcoCreateOptionsController extends Controller
{
    public function __construct(private readonly MigrantArcoChallengeService $challenges, private readonly MigrantArcoService $service) {}

    public function __invoke(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! in_array($actor->role, [UserRole::NonCoordinator, UserRole::Coordinator, UserRole::Admin], true)) {
            abort(403, 'This account cannot create ARCO requests.');
        }
        $data = $request->validate([
            'registryEntryId' => ['required', 'integer', 'exists:migrant_registry_entries,id'],
            'requestType' => ['required', Rule::in(config('features.arco_types', ['access']))],
            'reason' => ['required', 'string', 'max:2000'],
            'proposedPayload' => ['nullable', 'array'],
        ]);
        $entry = MigrantRegistryEntry::query()->findOrFail($data['registryEntryId']);
        if ($entry->current_status !== MigrantRegistryService::STATUS_APPROVED || $entry->pending_action !== null) {
            abort(409, 'Only approved registrations without pending changes can start an ARCO request.');
        }
        if (MigrantArcoRequest::query()->where('registry_entry_id', $entry->id)->whereIn('status', MigrantArcoService::ACTIVE_STATUSES)->exists()) {
            abort(409, 'This registration already has an active ARCO request.');
        }
        $proposal = $data['requestType'] === 'rectification' ? ($data['proposedPayload'] ?? null) : null;
        if ($data['requestType'] === 'rectification') {
            $payloadRequest = StoreMigrantRegistryRequest::create('/registry/migrants/arco/create/options', 'POST', ['payload_json' => $proposal]);
            $payloadRequest->setContainer(app());
            $payloadRequest->setRedirector(app('redirect'));
            $payloadRequest->validateResolved();
            $proposal = $payloadRequest->validated('payload_json');
        }
        $intent = [
            'entryId' => (int) $entry->id,
            'entryStatus' => $entry->current_status,
            'requestType' => $data['requestType'],
            'reason' => trim($data['reason']),
            'originalPayloadHash' => $this->service->payloadHash($entry->payload_json),
            'proposedPayload' => $proposal,
            'proposedPayloadHash' => is_array($proposal) ? $this->service->payloadHash($proposal) : null,
        ];

        return response()->json($this->challenges->issue($request, $actor, 'create', $intent, 'migrant_registry_entry', $entry->id));
    }
}
