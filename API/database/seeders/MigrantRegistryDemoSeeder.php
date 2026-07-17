<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\MigrantArcoArtifact;
use App\Models\MigrantArcoRequest;
use App\Models\MigrantArcoSignature;
use App\Models\MigrantArcoStatusHistory;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\SecurityChallengeIntent;
use App\Models\User;
use App\Services\Registry\MigrantArcoService;
use App\Services\Registry\MigrantRegistryService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrantRegistryDemoSeeder extends Seeder
{
    /** @var array<string, User> */
    private array $users = [];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('The migrant registry demo seeder can run only in the local environment.');
        }

        $this->loadUsers();
        Storage::disk('local')->deleteDirectory('migrant-registry');
        Storage::disk('local')->deleteDirectory('arco');

        DB::transaction(function (): void {
            AuditEvent::query()->where('event_type', 'like', 'migrant.%')->delete();
            SecurityChallengeIntent::query()
                ->whereIn('target_type', ['migrant_registry_entry', 'migrant_registry_document', 'migrant_arco_request'])
                ->delete();
            MigrantRegistryEntry::withTrashed()->forceDelete();

            $entries = collect($this->registrations())->mapWithKeys(function (array $specification, string $key): array {
                $entry = $this->createEntry($specification);
                $this->createDocuments($entry, $specification['documents']);

                return [$key => $entry];
            });

            $this->createCompletedAccess($entries->get('approved_access'));
            $this->createPendingRectification($entries->get('approved_rectification'));
            $this->createPendingCancellation($entries->get('approved_cancellation'));
        });

        $this->command?->info(sprintf(
            'Demo migrant registry created: %d registrations, %d documents, %d ARCO requests.',
            MigrantRegistryEntry::query()->count(),
            MigrantRegistryDocument::query()->count(),
            MigrantArcoRequest::query()->count(),
        ));
    }

    private function loadUsers(): void
    {
        foreach ([UserRole::Admin, UserRole::Coordinator, UserRole::NonCoordinator, UserRole::Volunteer] as $role) {
            $user = User::query()->where('role', $role->value)->where('status', 'active')->first()
                ?? User::query()->where('role', $role->value)->first();

            if (! $user instanceof User) {
                throw new \RuntimeException("Create at least one {$role->value} user before seeding migrant demo data.");
            }

            $this->users[$role->value] = $user;
        }
    }

    /** @param array<string, mixed> $specification */
    private function createEntry(array $specification): MigrantRegistryEntry
    {
        $creator = $this->users[$specification['creator_role']];
        $entry = MigrantRegistryEntry::query()->create([
            'created_by' => $creator->id,
            'created_by_role' => $creator->role?->value,
            'current_status' => $specification['status'],
            'current_assignee_role' => $specification['assignee_role'],
            'pending_action' => $specification['pending_action'],
            'pending_requested_by' => $specification['pending_payload'] ? $this->users[UserRole::NonCoordinator->value]->id : null,
            'pending_requested_by_role' => $specification['pending_payload'] ? UserRole::NonCoordinator->value : null,
            'payload_json' => $specification['payload'],
            'pending_payload_json' => $specification['pending_payload'],
        ]);
        $entry->forceFill([
            'created_at' => now()->subDays($specification['age_days']),
            'updated_at' => now()->subHours($specification['updated_hours']),
        ])->save();

        $previous = null;

        foreach ($specification['history'] as $index => $stage) {
            $actor = $this->users[$stage['role']];
            $signature = in_array($stage['status'], [
                MigrantRegistryService::STATUS_PENDING_APPROVAL,
                MigrantRegistryService::STATUS_APPROVED,
                MigrantRegistryService::STATUS_REJECTED,
            ], true) ? $this->createRegistrySignature($entry, $actor, match ($stage['status']) {
                MigrantRegistryService::STATUS_PENDING_APPROVAL => 'review_forwarded',
                MigrantRegistryService::STATUS_APPROVED => 'approve',
                default => 'reject',
            }, $entry->created_at->copy()->addHours($index + 1)) : null;
            $history = MigrantRegistryStatusHistory::query()->create([
                'registry_entry_id' => $entry->id,
                'from_status' => $previous,
                'to_status' => $stage['status'],
                'changed_by' => $actor->id,
                'changed_by_role' => $actor->role?->value,
                'reason' => $stage['reason'],
                'signature_id' => $signature?->id,
            ]);
            $history->forceFill([
                'created_at' => $entry->created_at->copy()->addHours($index + 1),
                'updated_at' => $entry->created_at->copy()->addHours($index + 1),
            ])->save();
            $previous = $stage['status'];
        }

        return $entry;
    }

    private function createRegistrySignature(MigrantRegistryEntry $entry, User $actor, string $action, \DateTimeInterface $verifiedAt): MigrantRegistrySignature
    {
        return MigrantRegistrySignature::query()->create([
            'registry_entry_id' => $entry->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role?->value,
            'action_type' => $action,
            'algorithm' => 'webauthn-es256-demo',
            'signature_payload' => json_encode(['demo' => true, 'action' => $action], JSON_THROW_ON_ERROR),
            'public_key_ref' => 'demo-passkey',
            'verified_at' => $verifiedAt,
        ]);
    }

    /** @param list<array{label: string, filename: string, title: string}> $documents */
    private function createDocuments(MigrantRegistryEntry $entry, array $documents): void
    {
        foreach ($documents as $index => $specification) {
            $contents = $this->pdf(
                $specification['title'],
                [
                    'Persona registrada' => (string) data_get($entry->payload_json, 'fullName'),
                    'Número de registro demo' => (string) $entry->id,
                    'Documento' => $specification['label'],
                    'Fecha de emisión demo' => now()->subMonths($index + 2)->format('d/m/Y'),
                    'Aviso' => 'Documento ficticio generado exclusivamente para demostración.',
                ],
            );
            $path = sprintf('migrant-registry/%d/documents/%s', $entry->id, $specification['filename']);
            Storage::disk('local')->put($path, $contents);
            $document = MigrantRegistryDocument::query()->create([
                'registry_entry_id' => $entry->id,
                'label' => $specification['label'],
                'original_file_name' => $specification['filename'],
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($contents),
                'sha256' => hash('sha256', $contents),
                'storage_disk' => 'local',
                'storage_path' => $path,
                'uploaded_by' => $entry->created_by,
                'uploaded_by_role' => $entry->created_by_role,
            ]);
            $document->forceFill([
                'created_at' => $entry->created_at->copy()->addMinutes($index + 10),
                'updated_at' => $entry->created_at->copy()->addMinutes($index + 10),
            ])->save();
        }
    }

    private function createCompletedAccess(MigrantRegistryEntry $entry): void
    {
        $requester = $this->users[UserRole::NonCoordinator->value];
        $coordinator = $this->users[UserRole::Coordinator->value];
        $completedAt = now()->subDay();
        $request = $this->createArcoRequest($entry, $requester, 'access', 'Entregar copia de la información y documentos registrados.', MigrantArcoService::STATUS_COMPLETED, $completedAt);
        $request->forceFill([
            'resolved_by' => $coordinator->id,
            'resolved_by_role' => $coordinator->role?->value,
            'resolution_reason' => 'Identidad verificada; paquete de acceso autorizado.',
            'completed_at' => $completedAt,
        ])->save();
        $this->createArcoSignature($request, $coordinator, 'coordinator_approved', $completedAt);
        $this->createArcoHistory($request, MigrantArcoService::STATUS_PENDING_COORDINATOR, MigrantArcoService::STATUS_COMPLETED, $coordinator, 'Acceso autorizado.', $completedAt);

        $contents = $this->pdf('Respuesta de Acceso ARCO - DEMO', [
            'Registro' => (string) $entry->id,
            'Persona' => (string) data_get($entry->payload_json, 'fullName'),
            'Documentos incluidos' => (string) $entry->documents()->count(),
            'Resultado' => 'Solicitud de acceso completada.',
        ]);
        $filename = "solicitud-arco-acceso-{$request->id}.pdf";
        $path = "arco/access/{$request->id}/{$filename}";
        Storage::disk('local')->put($path, $contents);
        MigrantArcoArtifact::query()->create([
            'arco_request_id' => $request->id,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'byte_size' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'generated_at' => $completedAt,
        ]);
    }

    private function createPendingRectification(MigrantRegistryEntry $entry): void
    {
        $requester = $this->users[UserRole::NonCoordinator->value];
        $request = $this->createArcoRequest(
            $entry,
            $requester,
            'rectification',
            'Corregir el estado de origen reportado durante la entrevista.',
            MigrantArcoService::STATUS_PENDING_COORDINATOR,
            null,
            [...$entry->payload_json, 'departmentState' => 'Francisco Morazán'],
        );
        $this->createArcoSignature($request, $requester, 'request_created', $request->created_at);
        $this->createArcoHistory($request, null, MigrantArcoService::STATUS_PENDING_COORDINATOR, $requester, $request->reason, $request->created_at);
    }

    private function createPendingCancellation(MigrantRegistryEntry $entry): void
    {
        $requester = $this->users[UserRole::NonCoordinator->value];
        $coordinator = $this->users[UserRole::Coordinator->value];
        $request = $this->createArcoRequest(
            $entry,
            $requester,
            'cancellation',
            'La persona solicitó eliminar su expediente después de concluir la atención.',
            MigrantArcoService::STATUS_PENDING_ADMIN,
        );
        $request->forceFill(['escalated_to_admin' => true])->save();
        $this->createArcoSignature($request, $requester, 'request_created', $request->created_at);
        $this->createArcoSignature($request, $coordinator, 'coordinator_approved', $request->created_at->copy()->addHour());
        $this->createArcoHistory($request, null, MigrantArcoService::STATUS_PENDING_COORDINATOR, $requester, $request->reason, $request->created_at);
        $this->createArcoHistory($request, MigrantArcoService::STATUS_PENDING_COORDINATOR, MigrantArcoService::STATUS_PENDING_ADMIN, $coordinator, 'Identidad y alcance verificados; se solicita decisión administrativa.', $request->created_at->copy()->addHour());
    }

    private function createArcoRequest(
        MigrantRegistryEntry $entry,
        User $requester,
        string $type,
        string $reason,
        string $status,
        ?\DateTimeInterface $completedAt = null,
        ?array $proposal = null,
    ): MigrantArcoRequest {
        $original = $entry->payload_json;
        $request = MigrantArcoRequest::query()->create([
            'registry_entry_id' => $entry->id,
            'requested_by' => $requester->id,
            'requested_by_role' => $requester->role?->value,
            'request_type' => $type,
            'reason' => $reason,
            'original_payload_json' => $original,
            'proposed_payload_json' => $proposal,
            'original_payload_hash' => $this->payloadHash($original),
            'proposed_payload_hash' => $proposal ? $this->payloadHash($proposal) : null,
            'status' => $status,
            'escalated_to_admin' => false,
            'completed_at' => $completedAt,
        ]);
        $request->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => $completedAt ?? now()->subDay(),
        ])->save();

        return $request;
    }

    private function createArcoSignature(MigrantArcoRequest $request, User $actor, string $action, \DateTimeInterface $verifiedAt): void
    {
        MigrantArcoSignature::query()->create([
            'arco_request_id' => $request->id,
            'actor_user_id' => $actor->id,
            'actor_role' => $actor->role?->value,
            'action_type' => $action,
            'algorithm' => 'webauthn-es256-demo',
            'signature_payload' => json_encode(['demo' => true, 'action' => $action], JSON_THROW_ON_ERROR),
            'public_key_ref' => 'demo-passkey',
            'verified_at' => $verifiedAt,
        ]);
    }

    private function createArcoHistory(MigrantArcoRequest $request, ?string $from, string $to, User $actor, string $reason, \DateTimeInterface $at): void
    {
        $history = MigrantArcoStatusHistory::query()->create([
            'arco_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor->id,
            'changed_by_role' => $actor->role?->value,
            'reason' => $reason,
        ]);
        $history->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, string> $facts */
    private function pdf(string $title, array $facts): string
    {
        $rows = collect($facts)->map(fn (string $value, string $label): string => sprintf(
            '<tr><th>%s</th><td>%s</td></tr>',
            e($label),
            e($value),
        ))->implode('');
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(sprintf(
            '<!doctype html><html lang="es"><meta charset="utf-8"><style>body{font-family:"DejaVu Sans",sans-serif;color:#172b45;font-size:11px}h1{color:#9d252c;font-size:20px}table{border-collapse:collapse;width:100%%}th,td{border-bottom:1px solid #d9dee5;padding:8px;text-align:left}th{width:34%%}.demo{margin:18px 0;padding:10px;border:2px solid #9d252c;color:#9d252c;font-weight:bold}</style><body><div class="demo">DOCUMENTO FICTICIO PARA DEMOSTRACIÓN</div><h1>%s</h1><table>%s</table></body></html>',
            e($title),
            $rows,
        ), 'UTF-8');
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return array<string, array<string, mixed>> */
    private function registrations(): array
    {
        $history = fn (string ...$statuses): array => collect($statuses)->map(fn (string $status): array => [
            'status' => $status,
            'role' => match ($status) {
                MigrantRegistryService::STATUS_PENDING_APPROVAL, MigrantRegistryService::STATUS_CHANGES_REQUESTED => UserRole::NonCoordinator->value,
                MigrantRegistryService::STATUS_APPROVED, MigrantRegistryService::STATUS_REJECTED => UserRole::Coordinator->value,
                default => UserRole::Volunteer->value,
            },
            'reason' => match ($status) {
                MigrantRegistryService::STATUS_PENDING_REVIEW => 'Registro demo enviado a revisión.',
                MigrantRegistryService::STATUS_PENDING_APPROVAL => 'Revisión operativa completada; pendiente de coordinación.',
                MigrantRegistryService::STATUS_CHANGES_REQUESTED => 'Se solicitó aclarar el teléfono de contacto y la procedencia.',
                MigrantRegistryService::STATUS_APPROVED => 'Registro demo aprobado por coordinación.',
                MigrantRegistryService::STATUS_REJECTED => 'Registro demo rechazado por información duplicada.',
                default => 'Transición de demostración.',
            },
        ])->all();
        $updateHistory = $history(
            MigrantRegistryService::STATUS_PENDING_REVIEW,
            MigrantRegistryService::STATUS_PENDING_APPROVAL,
            MigrantRegistryService::STATUS_APPROVED,
            MigrantRegistryService::STATUS_PENDING_REVIEW,
        );
        $updateHistory[array_key_last($updateHistory)]['role'] = UserRole::NonCoordinator->value;
        $updateHistory[array_key_last($updateHistory)]['reason'] = 'Modificación del registro aprobado enviada a revisión.';

        return [
            'new_review' => $this->registration('María Fernanda López Hernández', 'Honduras', 'Cortés', '1994-02-18', 'female', 'single', 'adult', '+52 81 5550 0101', MigrantRegistryService::STATUS_PENDING_REVIEW, UserRole::Volunteer->value, UserRole::NonCoordinator->value, MigrantRegistryService::ACTION_CREATE, null, 1, 3, $history(MigrantRegistryService::STATUS_PENDING_REVIEW), [
                ['label' => 'Identificación consular', 'filename' => 'identificacion-consular-demo.pdf', 'title' => 'Identificación Consular'],
                ['label' => 'Constancia de alojamiento', 'filename' => 'constancia-alojamiento-demo.pdf', 'title' => 'Constancia de Alojamiento'],
            ]),
            'pending_approval' => $this->registration('José Armando Martínez Cruz', 'El Salvador', 'San Salvador', '1987-11-03', 'male', 'married', 'adult', '+52 81 5550 0102', MigrantRegistryService::STATUS_PENDING_APPROVAL, UserRole::NonCoordinator->value, UserRole::Coordinator->value, MigrantRegistryService::ACTION_CREATE, null, 3, 8, $history(MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_PENDING_APPROVAL), [
                ['label' => 'Documento de identidad', 'filename' => 'documento-identidad-demo.pdf', 'title' => 'Documento de Identidad'],
            ]),
            'changes_requested' => $this->registration('Karla Sofía Ramírez García', 'Guatemala', 'Quetzaltenango', '2001-06-22', 'female', 'single', 'adult', '+52 81 5550 0103', MigrantRegistryService::STATUS_CHANGES_REQUESTED, UserRole::Volunteer->value, UserRole::Volunteer->value, MigrantRegistryService::ACTION_CREATE, null, 4, 12, $history(MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_CHANGES_REQUESTED), [
                ['label' => 'Acta de nacimiento', 'filename' => 'acta-nacimiento-demo.pdf', 'title' => 'Acta de Nacimiento'],
                ['label' => 'Ficha médica', 'filename' => 'ficha-medica-demo.pdf', 'title' => 'Ficha Médica de Ingreso'],
            ]),
            'approved_access' => $this->registration('Luis Alberto Mejía Torres', 'Venezuela', 'Zulia', '1983-09-14', 'male', 'divorced', 'adult', '+52 81 5550 0104', MigrantRegistryService::STATUS_APPROVED, UserRole::NonCoordinator->value, null, null, null, 12, 24, $history(MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_PENDING_APPROVAL, MigrantRegistryService::STATUS_APPROVED), [
                ['label' => 'Pasaporte', 'filename' => 'pasaporte-demo.pdf', 'title' => 'Pasaporte'],
                ['label' => 'Constancia de atención', 'filename' => 'constancia-atencion-demo.pdf', 'title' => 'Constancia de Atención Humanitaria'],
            ]),
            'approved_rectification' => $this->registration('Daniela Alejandra Ortiz Flores', 'Honduras', 'Comayagua', '1998-04-09', 'female', 'common_law_union', 'adult', '+52 81 5550 0105', MigrantRegistryService::STATUS_APPROVED, UserRole::NonCoordinator->value, null, null, null, 8, 30, $history(MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_PENDING_APPROVAL, MigrantRegistryService::STATUS_APPROVED), [
                ['label' => 'Tarjeta de identidad', 'filename' => 'tarjeta-identidad-demo.pdf', 'title' => 'Tarjeta de Identidad'],
            ]),
            'approved_cancellation' => $this->registration('Óscar Enrique Reyes Mendoza', 'Nicaragua', 'Estelí', '1976-12-01', 'male', 'widowed', 'adult', '+52 81 5550 0106', MigrantRegistryService::STATUS_APPROVED, UserRole::NonCoordinator->value, null, null, null, 15, 40, $history(MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_PENDING_APPROVAL, MigrantRegistryService::STATUS_APPROVED), [
                ['label' => 'Cédula de identidad', 'filename' => 'cedula-identidad-demo.pdf', 'title' => 'Cédula de Identidad'],
                ['label' => 'Formato de consentimiento', 'filename' => 'consentimiento-demo.pdf', 'title' => 'Consentimiento Informado'],
            ]),
            'rejected' => $this->registration('Pedro Antonio Castillo Rivas', 'Cuba', 'La Habana', '1991-07-27', 'male', 'single', 'adult', '+52 81 5550 0107', MigrantRegistryService::STATUS_REJECTED, UserRole::Volunteer->value, null, null, null, 6, 55, $history(MigrantRegistryService::STATUS_PENDING_REVIEW, MigrantRegistryService::STATUS_PENDING_APPROVAL, MigrantRegistryService::STATUS_REJECTED), [
                ['label' => 'Ficha de entrevista', 'filename' => 'ficha-entrevista-demo.pdf', 'title' => 'Ficha de Entrevista'],
            ]),
            'update_review' => $this->registration('Rosa Elena Dubón Aguilar', 'Honduras', 'Atlántida', '1962-05-16', 'female', 'separated', 'older_adult', '+52 81 5550 0108', MigrantRegistryService::STATUS_PENDING_REVIEW, UserRole::NonCoordinator->value, UserRole::NonCoordinator->value, MigrantRegistryService::ACTION_UPDATE, ['departmentState' => 'Yoro', 'notes' => 'DEMO: modificación solicitada para corregir el departamento de origen.'], 20, 2, $updateHistory, [
                ['label' => 'Documento de viaje', 'filename' => 'documento-viaje-demo.pdf', 'title' => 'Documento de Viaje'],
            ]),
        ];
    }

    /** @return array<string, mixed> */
    private function registration(
        string $fullName,
        string $country,
        string $state,
        string $birthDate,
        string $gender,
        string $civilStatus,
        string $populationGroup,
        string $phone,
        string $status,
        string $creatorRole,
        ?string $assigneeRole,
        ?string $pendingAction,
        ?array $pendingChanges,
        int $ageDays,
        int $updatedHours,
        array $history,
        array $documents,
    ): array {
        $parts = explode(' ', $fullName);
        $payload = [
            'attentionDate' => now()->subDays($ageDays)->format('Y-m-d'),
            'birthDate' => $birthDate,
            'civilStatus' => $civilStatus,
            'countryOfOrigin' => $country,
            'departmentState' => $state,
            'firstLastName' => $parts[count($parts) - 2],
            'firstName' => implode(' ', array_slice($parts, 0, -2)),
            'fullName' => $fullName,
            'gender' => $gender,
            'notes' => 'DEMO: expediente ficticio para capacitación y demostración del flujo de registro.',
            'phone' => $phone,
            'populationGroup' => $populationGroup,
            'secondLastName' => $parts[count($parts) - 1],
        ];

        return [
            'age_days' => $ageDays,
            'assignee_role' => $assigneeRole,
            'creator_role' => $creatorRole,
            'documents' => $documents,
            'history' => $history,
            'pending_action' => $pendingAction,
            'pending_payload' => $pendingChanges ? [...$payload, ...$pendingChanges] : null,
            'payload' => $payload,
            'status' => $status,
            'updated_hours' => $updatedHours,
        ];
    }
}
