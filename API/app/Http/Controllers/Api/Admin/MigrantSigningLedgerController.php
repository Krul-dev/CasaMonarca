<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class MigrantSigningLedgerController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $roles = [
            UserRole::Admin->value,
            UserRole::Coordinator->value,
            UserRole::NonCoordinator->value,
            UserRole::Volunteer->value,
        ];

        $signers = User::query()
            ->whereIn('role', $roles)
            ->withCount('migrantRegistrySignatures')
            ->orderBy('role')
            ->orderBy('name')
            ->get()
            ->map(fn (User $signer): array => [
                'id' => $signer->id,
                'name' => $signer->name,
                'email' => $signer->email,
                'role' => $signer->role?->value,
                'signatureCount' => (int) $signer->migrant_registry_signatures_count,
            ])
            ->values();

        $registrations = MigrantRegistryEntry::withTrashed()
            ->with([
                'signatures' => fn ($query) => $query
                    ->with('actor:id,name,email,role')
                    ->orderBy('verified_at')
                    ->orderBy('id'),
            ])
            ->latest('updated_at')
            ->latest('id')
            ->get()
            ->map(fn (MigrantRegistryEntry $entry): array => [
                'id' => $entry->id,
                'fullName' => $this->fullName($entry),
                'status' => $entry->current_status,
                'isPurged' => $entry->trashed(),
                'purgedAt' => $entry->deleted_at?->toISOString(),
                'createdAt' => $entry->created_at?->toISOString(),
                'updatedAt' => $entry->updated_at?->toISOString(),
                'signatures' => $entry->signatures
                    ->map(fn (MigrantRegistrySignature $signature): array => [
                        'id' => $signature->id,
                        'actionType' => $signature->action_type,
                        'algorithm' => $signature->algorithm,
                        'publicKeyRef' => $signature->public_key_ref,
                        'verifiedAt' => $signature->verified_at?->toISOString(),
                        'actor' => $signature->actor ? [
                            'id' => $signature->actor->id,
                            'name' => $signature->actor->name,
                            'email' => $signature->actor->email,
                            'role' => $signature->actor->role?->value,
                        ] : null,
                    ])
                    ->values(),
            ])
            ->values();

        return response()->json([
            'message' => 'Migrant signing ledger loaded successfully.',
            'signers' => $signers,
            'registrations' => $registrations,
        ]);
    }

    private function fullName(MigrantRegistryEntry $entry): string
    {
        $fullName = data_get($entry->payload_json, 'fullName');

        return is_string($fullName) && trim($fullName) !== ''
            ? trim($fullName)
            : "Registration #{$entry->id}";
    }
}
