<?php

namespace Tests\Unit;

use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\MigrantRegistrySignature;
use App\Models\MigrantRegistryStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class MigrantRegistryPdfTemplateTest extends TestCase
{
    public function test_template_renders_authoritative_record_history_curp_and_document_inventory(): void
    {
        $creator = new User(['name' => 'Ana Operadora', 'email' => 'ana@example.test', 'role' => 'volunteer']);
        $signer = new User(['name' => 'Clara Coordinadora', 'email' => 'clara@example.test', 'curp' => 'GODE561231HDFABC09']);
        $entry = new MigrantRegistryEntry([
            'created_by_role' => 'volunteer',
            'current_status' => 'approved',
            'payload_json' => ['fullName' => 'Nombre vigente'],
            'pending_payload_json' => ['fullName' => 'Nombre todavía no aprobado'],
        ]);
        $entry->id = 41;
        $entry->setRelation('creator', $creator);
        $signature = new MigrantRegistrySignature(['actor_role' => 'coordinator', 'action_type' => 'approve']);
        $signature->setRelation('actor', $signer);
        $history = new MigrantRegistryStatusHistory([
            'from_status' => 'pending_approval',
            'to_status' => 'approved',
            'changed_by_role' => 'coordinator',
            'reason' => 'Expediente completo',
        ]);
        $history->setRelation('changer', $signer);
        $document = new MigrantRegistryDocument([
            'label' => 'Identificación',
            'original_file_name' => 'pasaporte.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'uploaded_by_role' => 'volunteer',
        ]);
        $document->setRelation('uploader', $creator);
        $entry->setRelation('signatures', new Collection([$signature]));
        $entry->setRelation('statusHistory', new Collection([$history]));
        $entry->setRelation('documents', new Collection([$document]));

        $html = view('registry.registration-pdf', [
            'entry' => $entry,
            'questionnaireSections' => [[
                'title' => 'Datos personales',
                'answers' => [['question' => 'Nombre completo', 'answer' => 'Nombre vigente']],
            ]],
        ])->render();

        $this->assertStringContainsString('Nombre vigente', $html);
        $this->assertStringNotContainsString('Nombre todavía no aprobado', $html);
        $this->assertStringContainsString('GODE561231HDFABC09', $html);
        $this->assertStringContainsString('Expediente completo', $html);
        $this->assertStringContainsString('pasaporte.pdf', $html);
        $this->assertStringContainsString(str_repeat('a', 64), $html);
    }
}
