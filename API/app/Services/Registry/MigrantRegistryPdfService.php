<?php

namespace App\Services\Registry;

use App\Models\MigrantRegistryEntry;
use Dompdf\Dompdf;
use Dompdf\Options;

class MigrantRegistryPdfService
{
    public function __construct(
        private readonly MigrantQuestionnaireDefinitionService $questionnaireDefinitionService,
    ) {}

    public function render(MigrantRegistryEntry $entry): string
    {
        $this->loadExportRelations($entry);
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $payload = is_array($entry->payload_json) ? $entry->payload_json : [];
        $dompdf->loadHtml(view('registry.registration-pdf', [
            'entry' => $entry,
            'questionnaireSections' => $this->questionnaireDefinitionService->spanishAnswerSections($payload),
        ])->render(), 'UTF-8');
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }

    public function stateHash(MigrantRegistryEntry $entry): string
    {
        $this->loadExportRelations($entry);

        return hash('sha256', json_encode([
            'entry' => [
                'id' => $entry->getKey(),
                'createdBy' => $entry->created_by,
                'createdByRole' => $entry->created_by_role,
                'currentStatus' => $entry->current_status,
                'payload' => $entry->payload_json,
                'createdAt' => $entry->created_at?->toIso8601String(),
                'updatedAt' => $entry->updated_at?->toIso8601String(),
            ],
            'creator' => $entry->creator ? [
                'id' => $entry->creator->getKey(),
                'name' => $entry->creator->name,
                'email' => $entry->creator->email,
                'role' => $entry->creator->role?->value,
            ] : null,
            'signatures' => $entry->signatures->map(fn ($signature) => [
                'id' => $signature->getKey(),
                'action' => $signature->action_type,
                'role' => $signature->actor_role,
                'algorithm' => $signature->algorithm,
                'verifiedAt' => $signature->verified_at?->toIso8601String(),
                'actor' => $signature->actor ? [
                    'id' => $signature->actor->getKey(),
                    'name' => $signature->actor->name,
                    'email' => $signature->actor->email,
                    'curp' => $signature->actor->curp,
                ] : null,
            ])->values()->all(),
            'history' => $entry->statusHistory->map(fn ($history) => [
                'id' => $history->getKey(),
                'from' => $history->from_status,
                'to' => $history->to_status,
                'role' => $history->changed_by_role,
                'reason' => $history->reason,
                'createdAt' => $history->created_at?->toIso8601String(),
                'changer' => $history->changer ? [
                    'id' => $history->changer->getKey(),
                    'name' => $history->changer->name,
                    'email' => $history->changer->email,
                ] : null,
            ])->values()->all(),
            'documents' => $entry->documents->map(fn ($document) => [
                'id' => $document->getKey(),
                'label' => $document->label,
                'filename' => $document->original_file_name,
                'mimeType' => $document->mime_type,
                'sizeBytes' => $document->size_bytes,
                'sha256' => $document->sha256,
                'uploadedByRole' => $document->uploaded_by_role,
                'createdAt' => $document->created_at?->toIso8601String(),
                'uploader' => $document->uploader ? [
                    'id' => $document->uploader->getKey(),
                    'name' => $document->uploader->name,
                    'email' => $document->uploader->email,
                ] : null,
            ])->values()->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    public function loadExportRelations(MigrantRegistryEntry $entry): MigrantRegistryEntry
    {
        return $entry->load([
            'creator:id,name,email,role',
            'signatures' => fn ($query) => $query->oldest('id'),
            'signatures.actor:id,name,email,role,curp',
            'statusHistory' => fn ($query) => $query->oldest('id'),
            'statusHistory.changer:id,name,email,role',
            'documents' => fn ($query) => $query->whereNull('purged_at')->oldest('id'),
            'documents.uploader:id,name,email,role',
        ]);
    }
}
