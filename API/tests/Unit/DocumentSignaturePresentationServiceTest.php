<?php

namespace Tests\Unit;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignature;
use App\Models\User;
use App\Services\Documents\DocumentSignaturePresentationService;
use Dompdf\Dompdf;
use Illuminate\Support\Collection;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Tests\TestCase;

class DocumentSignaturePresentationServiceTest extends TestCase
{
    public function test_it_prepends_the_signature_record_to_a_supported_pdf(): void
    {
        [$document, $revision] = $this->models();
        $original = $this->pdf('<h1>Original page</h1>');

        $presentation = app(DocumentSignaturePresentationService::class)->generate(
            $document,
            $revision,
            'en',
            $original,
        );

        $this->assertSame('merged', $presentation['mode'], (string) $presentation['fallbackReason']);
        $this->assertNull($presentation['fallbackReason']);
        $this->assertStringStartsWith('%PDF-', $presentation['contents']);

        $reader = new Fpdi;
        $this->assertSame(
            2,
            $reader->setSourceFile(StreamReader::createByString($presentation['contents'])),
        );
    }

    public function test_it_returns_the_signature_summary_when_the_original_cannot_be_imported(): void
    {
        [$document, $revision] = $this->models();

        $presentation = app(DocumentSignaturePresentationService::class)->generate(
            $document,
            $revision,
            'es',
            'not-a-pdf',
        );

        $this->assertSame('summary-only', $presentation['mode']);
        $this->assertNotNull($presentation['fallbackReason']);
        $this->assertStringStartsWith('%PDF-', $presentation['contents']);
        $this->assertStringEndsWith('-signature-summary.pdf', $presentation['fileName']);
    }

    public function test_the_header_template_exposes_signer_curp_and_verification_notice(): void
    {
        [$document, $revision] = $this->models();

        $html = view('documents.signature-summary-pdf', [
            'document' => $document,
            'generatedAt' => '2026-08-07T12:00:00Z',
            'locale' => 'es',
            'policyFulfilled' => 0,
            'policyTotal' => 0,
            'revision' => $revision,
            'signatures' => collect([[
                'curp' => 'SABC560626MDFLRN01',
                'email' => 'coordinator@example.test',
                'expiresAt' => '2027-08-07T12:00:00Z',
                'fingerprint' => 'fingerprint-sha256',
                'id' => 31,
                'isExpired' => false,
                'name' => 'Coordinadora Demo',
                'role' => 'Coordinación',
                'signedAt' => '2026-08-07T12:00:00Z',
                'status' => 'verified',
            ]]),
        ])->render();

        $this->assertStringContainsString('SABC560626MDFLRN01', $html);
        $this->assertStringContainsString('fingerprint-sha256', $html);
        $this->assertStringContainsString('no es una firma PAdES', $html);
        $this->assertStringContainsString($revision->sha256, $html);
    }

    /** @return array{Document, DocumentRevision} */
    private function models(): array
    {
        $document = new Document;
        $document->forceFill([
            'id' => 10,
            'title' => 'Acuerdo de colaboración',
        ]);

        $signer = new User;
        $signer->forceFill([
            'id' => 20,
            'name' => 'Coordinadora Demo',
            'email' => 'coordinator@example.test',
            'role' => 'coordinator',
        ]);

        $signature = new DocumentSignature;
        $signature->forceFill([
            'id' => 31,
            'verification_status' => 'verified',
            'signed_at' => '2026-08-07 12:00:00',
            'metadata' => [
                'intent' => [
                    'version' => 2,
                    'signerCurp' => 'SABC560626MDFLRN01',
                ],
                'publicKeyFingerprintSha256' => 'fingerprint-sha256',
                'validity' => ['expiresAt' => '2027-08-07T12:00:00Z'],
            ],
        ]);
        $signature->setRelation('signedBy', $signer);

        $revision = new DocumentRevision;
        $revision->forceFill([
            'id' => 11,
            'document_id' => 10,
            'revision_number' => 2,
            'original_file_name' => 'acuerdo.pdf',
            'mime_type' => 'application/pdf',
            'sha256' => str_repeat('a', 64),
            'signature_status' => 'signed',
        ]);
        $revision->setRelation('signatures', new Collection([$signature]));
        $revision->setRelation('signatureRequirements', new Collection);

        return [$document, $revision];
    }

    private function pdf(string $html): string
    {
        $dompdf = new Dompdf;
        $dompdf->loadHtml($html);
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }
}
