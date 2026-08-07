<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\DocumentSignature;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use Throwable;

class DocumentSignaturePresentationService
{
    public function __construct(
        private readonly DocumentSignatureExpiryService $documentSignatureExpiryService,
    ) {}

    /**
     * @return array{contents: string, fallbackReason: string|null, fileName: string, locale: string, mode: 'merged'|'summary-only'}
     */
    public function generate(
        Document $document,
        DocumentRevision $revision,
        string $locale,
        ?string $revisionContents = null,
    ): array {
        $locale = $locale === 'en' ? 'en' : 'es';
        $revision->loadMissing(['signatures.signedBy', 'signatureRequirements']);
        $summary = $this->renderSummary($document, $revision, $locale);
        $baseName = $this->safeBaseName($revision->original_file_name);

        if ($revisionContents === null) {
            $revisionContents = Storage::disk($revision->storage_disk)->get($revision->storage_path);
        }

        try {
            return [
                'contents' => $this->prependSummary($summary, $revisionContents),
                'fallbackReason' => null,
                'fileName' => sprintf('%s-revision-%d-signatures.pdf', $baseName, $revision->revision_number),
                'locale' => $locale,
                'mode' => 'merged',
            ];
        } catch (Throwable $exception) {
            return [
                'contents' => $summary,
                'fallbackReason' => class_basename($exception),
                'fileName' => sprintf('%s-revision-%d-signature-summary.pdf', $baseName, $revision->revision_number),
                'locale' => $locale,
                'mode' => 'summary-only',
            ];
        }
    }

    private function renderSummary(Document $document, DocumentRevision $revision, string $locale): string
    {
        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('documents.signature-summary-pdf', [
            'document' => $document,
            'generatedAt' => now('UTC')->toIso8601String(),
            'locale' => $locale,
            'policyFulfilled' => $revision->signatureRequirements->whereNotNull('fulfilled_by_signature_id')->count(),
            'policyTotal' => $revision->signatureRequirements->count(),
            'revision' => $revision,
            'signatures' => $revision->signatures->sortBy('signed_at')->values()->map(
                fn (DocumentSignature $signature): array => $this->signatureRow($signature, $locale),
            ),
        ])->render());
        $dompdf->setPaper('letter');
        $dompdf->render();

        return $dompdf->output();
    }

    /** @return array<string, mixed> */
    private function signatureRow(DocumentSignature $signature, string $locale): array
    {
        $expiresAt = $this->documentSignatureExpiryService->resolveExpiresAt($signature);

        return [
            'curp' => data_get($signature->metadata, 'intent.version') === 2
                ? data_get($signature->metadata, 'intent.signerCurp')
                : null,
            'email' => $signature->signedBy?->email,
            'expiresAt' => $expiresAt?->utc()->toIso8601String(),
            'fingerprint' => data_get($signature->metadata, 'publicKeyFingerprintSha256'),
            'id' => $signature->getKey(),
            'isExpired' => $expiresAt?->isPast() ?? false,
            'name' => $signature->signedBy?->name,
            'role' => $this->roleLabel($signature->signedBy?->role?->value, $locale),
            'signedAt' => $signature->signed_at?->utc()->toIso8601String(),
            'status' => $signature->verification_status,
        ];
    }

    private function prependSummary(string $summary, string $revisionContents): string
    {
        $output = new Fpdi;
        $this->appendSource($output, $summary);
        $this->appendSource($output, $revisionContents);

        return $output->Output('S');
    }

    private function appendSource(Fpdi $output, string $contents): void
    {
        $pageCount = $output->setSourceFile(StreamReader::createByString($contents));

        for ($pageNumber = 1; $pageNumber <= $pageCount; $pageNumber++) {
            $template = $output->importPage($pageNumber);
            $size = $output->getTemplateSize($template);
            $orientation = $size['width'] > $size['height'] ? 'L' : 'P';
            $output->AddPage($orientation, [$size['width'], $size['height']]);
            $output->useTemplate($template);
        }
    }

    private function roleLabel(?string $role, string $locale): string
    {
        $labels = [
            'admin' => ['en' => 'Administrator', 'es' => 'Administración'],
            'coordinator' => ['en' => 'Coordinator', 'es' => 'Coordinación'],
            'non_coordinator' => ['en' => 'Non-coordinator', 'es' => 'No coordinador'],
            'volunteer' => ['en' => 'Volunteer', 'es' => 'Voluntariado'],
        ];

        return $labels[$role][$locale] ?? ($role ?: ($locale === 'en' ? 'Not available' : 'No disponible'));
    }

    private function safeBaseName(string $fileName): string
    {
        $baseName = pathinfo(basename($fileName), PATHINFO_FILENAME);
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $baseName);
        $safeName = is_string($safeName) ? trim($safeName, '.-') : '';

        return $safeName !== '' ? $safeName : 'document';
    }
}
