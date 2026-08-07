<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\DocumentAuthorizationService;
use App\Services\Documents\DocumentSignaturePresentationService;
use App\Services\Documents\DocumentVerificationBundleService;
use App\Services\Documents\StoredZipArchiveService;
use App\Services\Documents\VerificationPackageManifestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class DocumentVerificationPackageController extends Controller
{
    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly DocumentAuthorizationService $documentAuthorizationService,
        private readonly DocumentSignaturePresentationService $documentSignaturePresentationService,
        private readonly DocumentVerificationBundleService $documentVerificationBundleService,
        private readonly StoredZipArchiveService $storedZipArchiveService,
        private readonly VerificationPackageManifestService $verificationPackageManifestService,
    ) {}

    public function __invoke(Request $request, Document $document, ?DocumentRevision $revision = null): Response|JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $validated = $request->validate(['locale' => ['nullable', 'in:en,es']]);
        $locale = (string) ($validated['locale'] ?? 'es');

        if ($revision !== null) {
            abort_unless(
                (int) $revision->document_id === (int) $document->getKey(),
                404,
                'Selected document revision could not be found.',
            );

            if (! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
                return $this->documentAuthorizationService->forbiddenResponse(
                    $request,
                    $user,
                    'history.read',
                    $document,
                    $revision,
                );
            }

            $document->load('owner');
            $revision->load(['createdBy', 'signatures.signedBy', 'signatureRequirements']);
        } else {
            $document->load([
                'owner',
                'currentRevision.createdBy',
                'currentRevision.signatures.signedBy',
                'currentRevision.signatureRequirements',
            ]);

            $revision = $document->currentRevision;

            abort_unless($revision !== null, 404, 'Current document revision could not be found.');

            if (! $this->documentAuthorizationService->canReadRevision($user, $document, $revision)) {
                return $this->documentAuthorizationService->forbiddenResponse(
                    $request,
                    $user,
                    'document.verification_package.read',
                    $document,
                    $revision,
                );
            }
        }

        abort_unless(
            Storage::disk($revision->storage_disk)->exists($revision->storage_path),
            404,
            'Document revision file could not be found.',
        );

        $bundle = $this->documentVerificationBundleService->build($document, $revision);
        $revisionFileName = $this->safeFileName($revision->original_file_name);
        $verificationJson = json_encode(
            $bundle,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $verificationCanonicalSha256 = hash(
            'sha256',
            $this->verificationPackageManifestService->canonicalJson($bundle),
        );
        $zipFileName = sprintf(
            '%s-revision-%s-verification-package.zip',
            $this->safeSlug($document->title),
            $revision->revision_number,
        );
        $revisionContents = Storage::disk($revision->storage_disk)->get($revision->storage_path);
        $presentation = strtolower((string) $revision->mime_type) === 'application/pdf' && $revision->signatures->isNotEmpty()
            ? $this->documentSignaturePresentationService->generate(
                $document,
                $revision,
                $locale,
                $revisionContents,
            )
            : null;
        $readme = $this->readme($revisionFileName, $presentation);
        $verifierTemplateSha256 = hash('sha256', $this->verifyHtmlTemplate());
        $manifest = $this->manifest(
            $document,
            $revision,
            $revisionFileName,
            $revisionContents,
            $verificationJson."\n",
            $verificationCanonicalSha256,
            $readme,
            $verifierTemplateSha256,
            $presentation,
        );
        $signedManifest = $this->verificationPackageManifestService->sign($manifest);
        $manifestJson = json_encode(
            $signedManifest['manifest'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $manifestSignatureJson = json_encode(
            $signedManifest['signature'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";
        $signedManifestJson = json_encode(
            $signedManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $verifyHtml = $this->verifyHtml($verificationJson."\n", $signedManifestJson);
        $verifyHtmlSignature = $this->verificationPackageManifestService->signPayload($verifyHtml, 'verify.html');
        $verifyHtmlSignatureJson = json_encode(
            $verifyHtmlSignature,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n";

        $zipFiles = [
            [
                'name' => $revisionFileName,
                'contents' => $revisionContents,
            ],
            [
                'name' => 'verification.json',
                'contents' => $verificationJson."\n",
            ],
            [
                'name' => 'README.md',
                'contents' => $readme,
            ],
            [
                'name' => 'manifest.json',
                'contents' => $manifestJson,
            ],
            [
                'name' => 'manifest.signature.json',
                'contents' => $manifestSignatureJson,
            ],
            [
                'name' => 'verify.html.signature.json',
                'contents' => $verifyHtmlSignatureJson,
            ],
            [
                'name' => 'verify.html',
                'contents' => $verifyHtml,
            ],
        ];

        if ($presentation !== null) {
            $zipFiles[] = [
                'name' => $presentation['fileName'],
                'contents' => $presentation['contents'],
            ];
        }

        if (($verifyHtmlSignature['status'] ?? null) === 'signed') {
            $zipFiles[] = [
                'name' => 'verify.html.public.pem',
                'contents' => (string) $verifyHtmlSignature['publicKeyPem'],
            ];
            $zipFiles[] = [
                'name' => 'verify.html.signature.bin',
                'contents' => $this->base64UrlDecode((string) $verifyHtmlSignature['value']),
            ];
        }

        $zipContents = $this->storedZipArchiveService->build($zipFiles);

        $this->auditEventService->success(
            $request,
            AuditEventType::DocumentVerificationPackageDownloaded,
            $user,
            [
                'type' => 'document_revision',
                'id' => $revision->getKey(),
                'documentId' => $document->getKey(),
                'revisionId' => $revision->getKey(),
            ],
            [
                'revisionNumber' => $revision->revision_number,
                'signatureCount' => $revision->signatures->count(),
                'signaturePresentationMode' => $presentation['mode'] ?? null,
                'zipFileName' => $zipFileName,
            ],
        );

        return response($zipContents, 200, [
            'Content-Disposition' => sprintf('attachment; filename="%s"', $zipFileName),
            'Content-Length' => (string) strlen($zipContents),
            'Content-Type' => 'application/zip',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(
        Document $document,
        DocumentRevision $revision,
        string $revisionFileName,
        string $revisionContents,
        string $verificationJson,
        string $verificationCanonicalSha256,
        string $readme,
        string $verifierTemplateSha256,
        ?array $presentation,
    ): array {
        $files = [
            [
                'name' => $revisionFileName,
                'role' => 'revision',
                'sha256' => hash('sha256', $revisionContents),
                'size' => strlen($revisionContents),
            ],
            [
                'name' => 'verification.json',
                'role' => 'verification-evidence',
                'sha256' => hash('sha256', $verificationJson),
                'canonicalSha256' => $verificationCanonicalSha256,
                'size' => strlen($verificationJson),
            ],
            [
                'name' => 'README.md',
                'role' => 'instructions',
                'sha256' => hash('sha256', $readme),
                'size' => strlen($readme),
            ],
            [
                'name' => 'verify.html',
                'role' => 'standalone-verifier',
                'sha256' => $verifierTemplateSha256,
                'hashMode' => 'template-with-embedded-data-placeholders',
            ],
        ];

        if ($presentation !== null) {
            $files[] = [
                'name' => $presentation['fileName'],
                'role' => $presentation['mode'] === 'merged'
                    ? 'signature-presentation'
                    : 'signature-summary',
                'sha256' => hash('sha256', $presentation['contents']),
                'size' => strlen($presentation['contents']),
                'locale' => $presentation['locale'],
                'presentationMode' => $presentation['mode'],
            ];
        }

        return [
            'version' => 1,
            'packageType' => 'casa-monarca.document-verification',
            'generatedAt' => Carbon::now()->toIso8601String(),
            'document' => [
                'id' => $document->getKey(),
                'title' => $document->title,
                'revisionId' => $revision->getKey(),
                'revisionNumber' => $revision->revision_number,
                'revisionSha256' => $revision->sha256,
            ],
            'files' => $files,
            'verification' => [
                'evidenceSha256' => hash('sha256', $verificationJson),
                'expectedDocumentSha256' => $revision->sha256,
                'signatureCount' => $revision->signatures->count(),
            ],
        ];
    }

    /**
     * @param  array{fileName: string, mode: string}|null  $presentation
     */
    private function readme(string $revisionFileName, ?array $presentation): string
    {
        $presentationDescription = $presentation === null
            ? ''
            : ($presentation['mode'] === 'merged'
                ? "\n- `{$presentation['fileName']}`: a presentation copy with a visual signature record prepended to the original pages. `verify.html` can verify this file against the signed package manifest; the WebAuthn signatures still bind the original revision hash rather than this presentation's bytes."
                : "\n- `{$presentation['fileName']}`: a standalone visual signature record. The original PDF could not be imported safely, so the package kept it separate. It is not the signed byte sequence and must not be used as the verification target.");

        return <<<MARKDOWN
        # Casa Monarca Document Verification Package

        This package contains one document revision and the evidence needed to verify its passkey signature.

        ## Files

        - `{$revisionFileName}`: the exact document revision that was signed.
        - `verification.json`: signature metadata, public key, WebAuthn assertion, and expected SHA-256 hash.
        - `manifest.json`: server-generated package manifest covering the package files.
        - `manifest.signature.json`: Casa Monarca signature metadata for `manifest.json`.
        - `verify.html`: a standalone verifier with embedded verification metadata. Open it and drop/select the revision file.
        - `verify.html.signature.json`: Casa Monarca signature metadata for the standalone verifier.
        - `verify.html.public.pem` and `verify.html.signature.bin`: detached signature files for checking whether `verify.html` was modified. These files are present when package signing is configured.
        {$presentationDescription}

        ## Verifier Tamper Check

        From the extracted package directory, run:

        ```sh
        openssl dgst -sha256 -verify verify.html.public.pem -signature verify.html.signature.bin verify.html
        ```

        Expected output: `Verified OK`.

        This checks the standalone verifier file itself. Also compare the public key
        fingerprint in `verify.html.signature.json` with the package-signing fingerprint
        published in the Casa Monarca admin panel.

        ## Trust Boundary

        The package manifest is signed when the server has
        `VERIFICATION_PACKAGE_SIGNING_PRIVATE_KEY_BASE64` and
        `VERIFICATION_PACKAGE_SIGNING_PUBLIC_KEY_BASE64` configured. The verifier checks
        that signed manifest before reporting the package as fully verified.

        The standalone `verify.html` is still a convenience verifier, not a tamper-proof
        authority. If someone edits the HTML, they can also edit the visual output shown by
        that HTML. The reliable evidence is the signed manifest, the document hash, the
        WebAuthn signature evidence in `verification.json`, and the fingerprints shown by
        the verifier.

        ## Expected Checks

        The verifier confirms:

        1. The package manifest signature is valid when a package signing key is configured.
        2. The selected original revision matches its expected SHA-256, or the selected presentation PDF matches the signed manifest entry.
        3. The embedded verification evidence and original revision hash match the signed package manifest.
        4. The WebAuthn client data challenge matches the canonical signing intent.
        5. The RP ID hash in authenticator data matches the expected RP ID.
        6. The user-presence flag is set.
        7. The cryptographic signature verifies with the stored public key.
        8. The signature has not expired according to `expiresAt`.
        9. For version 2 signatures with a CURP, its format is valid and it matches the CURP bound into the signed intent.

        If any check fails, treat the package as not verified.

        MARKDOWN;
    }

    private function base64UrlDecode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padded = $normalized.str_repeat('=', (4 - strlen($normalized) % 4) % 4);

        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    private function verifyHtml(string $verificationJson, string $signedManifestJson): string
    {
        return str_replace(
            ['__EMBEDDED_VERIFICATION_JSON__', '__EMBEDDED_SIGNED_MANIFEST_JSON__'],
            [
                str_replace('</script', '<\/script', $verificationJson),
                str_replace('</script', '<\/script', $signedManifestJson),
            ],
            $this->verifyHtmlTemplate(),
        );
    }

    private function verifyHtmlTemplate(): string
    {
        return <<<'HTML'
        <!doctype html>
        <html lang="es">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Casa Monarca | Verificación de documentos</title>
          <style>
            :root { color-scheme: light; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #13233a; background: #f3eee5; }
            body { margin: 0; padding: 2rem; }
            main { max-width: 920px; margin: 0 auto; padding: 2rem; border: 1px solid rgba(19,35,58,.12); border-radius: 24px; background: #fffaf2; box-shadow: 0 18px 48px rgba(19,35,58,.12); }
            .language-switch { display: inline-grid; grid-template-columns: repeat(2, 2.4rem); margin-bottom: 1rem; padding: .2rem; border: 1px solid rgba(19,35,58,.18); border-radius: .5rem; }
            .language-switch button { min-width: 0; margin: 0; padding: .6rem .4rem; border: 0; border-radius: .32rem; background: transparent; color: #13233a; }
            .language-switch button[aria-pressed="true"] { position: relative; border-color: #1e4f89; background: #1e4f89; color: #fff; }
            h1 { margin: 0; font-size: clamp(2rem, 6vw, 4rem); line-height: .95; letter-spacing: -.05em; }
            p { color: #536176; line-height: 1.6; }
            input { padding: .85rem; border: 1px solid rgba(19,35,58,.18); border-radius: 14px; background: #fff; }
            button { margin-top: 1rem; padding: .8rem 1rem; border: 0; border-radius: 999px; background: #1e4f89; color: white; font-weight: 900; cursor: pointer; }
            button:disabled { opacity: .6; cursor: wait; }
            .drop-zone { display: block; margin-top: 1rem; padding: 2rem; border: 2px dashed rgba(30,79,137,.28); border-radius: 20px; background: rgba(30,79,137,.06); text-align: center; cursor: pointer; transition: border-color .15s ease, background .15s ease, transform .15s ease; }
            .drop-zone:focus-within { outline: 3px solid rgba(30,79,137,.18); outline-offset: 3px; }
            .drop-zone strong { display: block; color: #13233a; font-size: 1.05rem; }
            .drop-zone span { display: block; margin-top: .35rem; color: #536176; }
            .drop-zone input { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; clip-path: inset(50%); }
            .drop-zone.is-dragging { border-color: #1e4f89; background: rgba(30,79,137,.12); transform: translateY(-1px); }
            .file-pill { display: inline-flex; margin-top: .75rem; padding: .45rem .7rem; border-radius: 999px; background: rgba(35,125,84,.12); color: #1f5f40; font-weight: 850; }
            .result { margin-top: 1.2rem; padding: 1rem; border-radius: 18px; background: #eef4ed; }
            .result--fail { background: #f4ded9; }
            .package-status { margin-top: 1rem; padding: .9rem 1rem; border: 1px solid rgba(35,125,84,.18); border-radius: 16px; background: rgba(35,125,84,.08); color: #1f5f40; }
            .package-status--fallback { border-color: rgba(143,83,8,.24); background: rgba(143,83,8,.09); color: #784504; }
            .check { display: flex; justify-content: space-between; gap: 1rem; padding: .65rem 0; border-bottom: 1px solid rgba(19,35,58,.08); }
            .check strong { overflow-wrap: anywhere; }
            .pass { color: #207a4e; font-weight: 900; }
            .fail { color: #9d352e; font-weight: 900; }
            .fingerprints { display: grid; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); gap: .75rem; margin-top: 1rem; }
            .fingerprint-card { padding: .85rem; border: 1px solid rgba(19,35,58,.1); border-radius: 16px; background: rgba(19,35,58,.045); }
            .fingerprint-card span { display: block; color: #8f5308; font-size: .72rem; font-weight: 900; letter-spacing: .08em; text-transform: uppercase; }
            .fingerprint-card code { display: block; margin-top: .35rem; overflow-wrap: anywhere; color: #13233a; font-size: .78rem; line-height: 1.45; }
            .tamper-check { margin-top: 1rem; padding: 1rem; border: 1px solid rgba(19,35,58,.1); border-radius: 18px; background: rgba(255,255,255,.54); }
            .tamper-check h2 { margin: 0 0 .45rem; font-size: 1rem; }
            .tamper-check p { margin: .45rem 0 0; }
            code, pre { font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace; }
            pre { overflow: auto; padding: 1rem; border-radius: 16px; background: rgba(19,35,58,.06); }
          </style>
        </head>
        <body>
          <main>
            <nav class="language-switch" aria-label="Idioma" data-i18n-aria-label="languageSwitch">
              <button data-locale="en" type="button">EN</button>
              <button data-locale="es" type="button">ES</button>
            </nav>
            <p><strong>CASA MONARCA</strong></p>
            <h1 data-i18n="title">Paquete de verificación</h1>
            <p data-i18n="intro">Esta página contiene la evidencia de verificación. Arrastra la versión original o el PDF de consulta con encabezado de firmas para verificarlo localmente.</p>
            <section id="packageStatus" class="package-status" data-i18n="evidenceLoaded">Evidencia de verificación cargada desde este archivo HTML.</section>
            <section class="fingerprints" aria-label="Huellas del paquete" data-i18n-aria-label="fingerprints">
              <div class="fingerprint-card">
                <span data-i18n="evidenceHash">Hash de la evidencia</span>
                <code id="evidenceHash" data-i18n="calculating">Calculando...</code>
              </div>
              <div class="fingerprint-card">
                <span data-i18n="verifierHash">Hash del verificador HTML</span>
                <code id="verifierHash" data-i18n="calculating">Calculando...</code>
              </div>
              <div class="fingerprint-card">
                <span data-i18n="expectedHash">Hash esperado del documento</span>
                <code id="expectedDocumentHash" data-i18n="notAvailable">No disponible</code>
              </div>
              <div class="fingerprint-card">
                <span data-i18n="manifestSignature">Firma del manifiesto</span>
                <code id="manifestSignatureStatus" data-i18n="checking">Comprobando...</code>
              </div>
              <div class="fingerprint-card">
                <span data-i18n="manifestHash">Hash del manifiesto</span>
                <code id="manifestHash" data-i18n="calculating">Calculando...</code>
              </div>
              <div class="fingerprint-card">
                <span data-i18n="signingKey">Llave de firma del paquete</span>
                <code id="packageSigningKey" data-i18n="notConfigured">No configurada</code>
              </div>
              <div class="fingerprint-card">
                <span data-i18n="signedVerifierHash">Hash firmado de la plantilla del verificador</span>
                <code id="signedVerifierTemplateHash" data-i18n="notAvailable">No disponible</code>
              </div>
            </section>
            <section class="tamper-check" aria-label="Comprobación de integridad del verificador" data-i18n-aria-label="tamperCheck">
              <h2 data-i18n="tamperCheck">Comprobación de integridad del verificador</h2>
              <p data-i18n="tamperInstructions">Antes de usar este verificador independiente, extrae el paquete y ejecuta:</p>
              <pre><code>openssl dgst -sha256 -verify verify.html.public.pem -signature verify.html.signature.bin verify.html</code></pre>
              <p><span data-i18n="expectedOutput">Resultado esperado:</span> <code>Verified OK</code>. <span data-i18n="compareFingerprint">Compara la huella de la llave de firma mostrada arriba con la publicada por administración.</span></p>
            </section>

            <label id="dropZone" class="drop-zone" for="revisionFile">
              <strong data-i18n="dropFile">Arrastra la versión original o el PDF con encabezado de firmas</strong>
              <span data-i18n="chooseFile">o haz clic en esta área para seleccionarlo</span>
              <span id="selectedFileName" class="file-pill" hidden></span>
              <input id="revisionFile" type="file">
            </label>
            <button id="verifyButton" type="button" data-i18n="verify">Verificar paquete</button>
            <section id="output" class="result" hidden></section>
          </main>

          <script id="embeddedVerificationBundle" type="application/json">__EMBEDDED_VERIFICATION_JSON__</script>
          <script id="embeddedSignedManifest" type="application/json">__EMBEDDED_SIGNED_MANIFEST_JSON__</script>
          <script>
            const translations = {
              en: {
                calculating: 'Calculating...', checking: 'Checking...', chooseFile: 'or click this area to choose it', compareFingerprint: 'Compare the package-signing key fingerprint above with the admin-published fingerprint.', configuredSigningKey: 'configured package signing key', signed: 'signed', evidenceHash: 'Evidence hash', evidenceLoaded: 'Verification evidence loaded from this HTML file.', expectedHash: 'Expected document hash', expectedOutput: 'Expected output:', fingerprints: 'Package fingerprints', intro: 'This page embeds the verification evidence. Drop the original revision or the presentation PDF with its signature header to verify it locally.', languageSwitch: 'Language', manifestHash: 'Manifest hash', manifestSignature: 'Manifest signature', notAvailable: 'Not available', notConfigured: 'Not configured', packageEvidenceUnsigned: 'Verification evidence is embedded, but this package manifest is not signed by the server.', packageEvidenceVerified: 'Verification evidence and package manifest signature loaded from this HTML file.', signedVerifierHash: 'Signed verifier template hash', signingKey: 'Package signing key', tamperCheck: 'Verifier tamper check', tamperInstructions: 'Before using this standalone verifier, extract the package and run:', title: 'Verification package', verify: 'Verify package', verifierHash: 'Verifier HTML hash', dropFile: 'Drop the original revision or PDF with signature header here',
                unsignedManifest: 'Unsigned package manifest: {reason}', signingKeyMissing: 'package signing key not configured', unsigned: 'Unsigned', manifestMismatch: 'Manifest hash does not match the signed manifest hash.', hashMismatch: 'Hash mismatch', signedBy: 'Signed by {key}', invalidManifestSignature: 'Package manifest signature is invalid.', verifiedKey: 'Verified ({key})', failed: 'Failed', notVerifiedYet: 'Package manifest signature has not been verified yet.', verificationPassed: 'Verification passed', verificationFailed: 'Verification failed', compareEvidence: 'Use the fingerprints above to compare this verifier, embedded evidence, and signed manifest with a trusted copy.', info: 'info', ok: 'ok', checkFailed: 'failed', ready: 'Ready to verify {file} against embedded evidence.', selectFileFirst: 'Drop or select the original revision or presentation PDF first.', manifestCheck: 'Package manifest signature', fileHash: 'Original revision hash', presentationFileHash: 'Presentation PDF manifest hash', presentationNotice: 'Presentation PDF trust boundary', presentationDetail: 'This PDF is verified as the presentation copy covered by the signed package manifest. The WebAuthn signatures bind the original revision hash, not the presentation PDF bytes.', evidenceManifestHash: 'Embedded evidence manifest hash', revisionManifestBinding: 'Original revision manifest binding', noSignatures: 'No signatures are present in verification.json.', incompleteEvidence: 'Signature evidence is incomplete.', canonicalIntent: 'Canonical intent', challenge: 'Challenge', revisionBinding: 'Revision hash binding', signerCurp: 'Signer CURP', noCurp: 'No CURP was provided for this signature.', curpFormat: 'CURP format', curpBinding: 'CURP signed binding', legacyCurp: 'Legacy signature created before CURP binding.', clientDataType: 'Client data type', origin: 'Origin', clientChallenge: 'Client challenge', authenticatorShort: 'Authenticator data is too short.', rpIdHash: 'RP ID hash', userPresence: 'User presence', signatureExpiry: 'Signature expiry', cryptoSignature: 'Cryptographic signature by {signer}', unknownSigner: 'unknown signer', unexpectedFailure: 'Verification failed unexpectedly.', unsupportedAlgorithm: 'Unsupported public key algorithm: {algorithm}', invalidDer: 'ECDSA signature is not DER encoded.', invalidInteger: 'Invalid DER integer.', coordinateLong: 'ECDSA coordinate is too long.'
              },
              es: {
                calculating: 'Calculando...', checking: 'Comprobando...', chooseFile: 'o haz clic en esta área para seleccionarlo', compareFingerprint: 'Compara la huella de la llave de firma mostrada arriba con la publicada por administración.', configuredSigningKey: 'llave de firma configurada', signed: 'firmada', evidenceHash: 'Hash de la evidencia', evidenceLoaded: 'Evidencia de verificación cargada desde este archivo HTML.', expectedHash: 'Hash esperado del documento', expectedOutput: 'Resultado esperado:', fingerprints: 'Huellas del paquete', intro: 'Esta página contiene la evidencia de verificación. Arrastra la versión original o el PDF de consulta con encabezado de firmas para verificarlo localmente.', languageSwitch: 'Idioma', manifestHash: 'Hash del manifiesto', manifestSignature: 'Firma del manifiesto', notAvailable: 'No disponible', notConfigured: 'No configurada', packageEvidenceUnsigned: 'La evidencia está integrada, pero el servidor no firmó el manifiesto de este paquete.', packageEvidenceVerified: 'La evidencia y la firma del manifiesto se cargaron desde este archivo HTML.', signedVerifierHash: 'Hash firmado de la plantilla del verificador', signingKey: 'Llave de firma del paquete', tamperCheck: 'Comprobación de integridad del verificador', tamperInstructions: 'Antes de usar este verificador independiente, extrae el paquete y ejecuta:', title: 'Paquete de verificación', verify: 'Verificar paquete', verifierHash: 'Hash del verificador HTML', dropFile: 'Arrastra la versión original o el PDF con encabezado de firmas',
                unsignedManifest: 'Manifiesto del paquete sin firma: {reason}', signingKeyMissing: 'la llave de firma no está configurada', unsigned: 'Sin firma', manifestMismatch: 'El hash del manifiesto no coincide con el hash firmado.', hashMismatch: 'El hash no coincide', signedBy: 'Firmado por {key}', invalidManifestSignature: 'La firma del manifiesto del paquete no es válida.', verifiedKey: 'Verificada ({key})', failed: 'Falló', notVerifiedYet: 'La firma del manifiesto aún no se ha verificado.', verificationPassed: 'Verificación correcta', verificationFailed: 'La verificación falló', compareEvidence: 'Usa las huellas anteriores para comparar este verificador, la evidencia integrada y el manifiesto firmado con una copia confiable.', info: 'información', ok: 'correcto', checkFailed: 'falló', ready: 'Listo para verificar {file} con la evidencia integrada.', selectFileFirst: 'Primero arrastra o selecciona la versión original o el PDF de consulta.', manifestCheck: 'Firma del manifiesto del paquete', fileHash: 'Hash de la versión original', presentationFileHash: 'Hash del PDF de consulta en el manifiesto', presentationNotice: 'Límite de confianza del PDF de consulta', presentationDetail: 'Este PDF se verifica como la copia de consulta cubierta por el manifiesto firmado del paquete. Las firmas WebAuthn vinculan el hash de la versión original, no los bytes del PDF de consulta.', evidenceManifestHash: 'Hash de la evidencia integrada en el manifiesto', revisionManifestBinding: 'Vinculación de la versión original con el manifiesto', noSignatures: 'No hay firmas en verification.json.', incompleteEvidence: 'La evidencia de la firma está incompleta.', canonicalIntent: 'Intención canónica', challenge: 'Desafío', revisionBinding: 'Vinculación del hash de la versión', signerCurp: 'CURP del firmante', noCurp: 'No se proporcionó CURP para esta firma.', curpFormat: 'Formato de CURP', curpBinding: 'Vinculación firmada de CURP', legacyCurp: 'Firma anterior a la vinculación de CURP.', clientDataType: 'Tipo de datos del cliente', origin: 'Origen', clientChallenge: 'Desafío del cliente', authenticatorShort: 'Los datos del autenticador son demasiado cortos.', rpIdHash: 'Hash del ID de parte de confianza', userPresence: 'Presencia del usuario', signatureExpiry: 'Vencimiento de la firma', cryptoSignature: 'Firma criptográfica de {signer}', unknownSigner: 'firmante desconocido', unexpectedFailure: 'La verificación falló de forma inesperada.', unsupportedAlgorithm: 'Algoritmo de llave pública no compatible: {algorithm}', invalidDer: 'La firma ECDSA no usa codificación DER.', invalidInteger: 'Entero DER no válido.', coordinateLong: 'La coordenada ECDSA es demasiado larga.'
              },
            }
            const localeStorageKey = 'casamonarca.verifier.locale'
            let locale = (() => { try { return localStorage.getItem(localeStorageKey) === 'en' ? 'en' : 'es' } catch { return 'es' } })()
            const tr = (key, values = {}) => Object.entries(values).reduce((message, [name, value]) => message.replaceAll(`{${name}}`, String(value)), translations[locale][key] || translations.en[key] || key)
            const applyLocale = () => {
              document.documentElement.lang = locale
              document.title = `Casa Monarca | ${tr('title')}`
              document.querySelectorAll('[data-i18n]').forEach((element) => { element.textContent = tr(element.dataset.i18n) })
              document.querySelectorAll('[data-i18n-aria-label]').forEach((element) => { element.setAttribute('aria-label', tr(element.dataset.i18nAriaLabel)) })
              document.querySelectorAll('[data-locale]').forEach((button) => { button.setAttribute('aria-pressed', String(button.dataset.locale === locale)) })
            }
            const textDecoder = new TextDecoder()
            const embeddedBundleElement = document.querySelector('#embeddedVerificationBundle')
            const embeddedBundleSource = embeddedBundleElement.textContent || '{}'
            const embeddedBundle = JSON.parse(embeddedBundleSource)
            const embeddedSignedManifestElement = document.querySelector('#embeddedSignedManifest')
            const embeddedSignedManifestSource = embeddedSignedManifestElement.textContent || '{}'
            const embeddedSignedManifest = JSON.parse(embeddedSignedManifestSource)
            const dropZone = document.querySelector('#dropZone')
            const evidenceHashElement = document.querySelector('#evidenceHash')
            const expectedDocumentHashElement = document.querySelector('#expectedDocumentHash')
            const manifestHashElement = document.querySelector('#manifestHash')
            const manifestSignatureStatusElement = document.querySelector('#manifestSignatureStatus')
            const output = document.querySelector('#output')
            const packageStatus = document.querySelector('#packageStatus')
            const packageSigningKeyElement = document.querySelector('#packageSigningKey')
            const revisionFileInput = document.querySelector('#revisionFile')
            const selectedFileName = document.querySelector('#selectedFileName')
            const signedVerifierTemplateHashElement = document.querySelector('#signedVerifierTemplateHash')
            const verifierHashElement = document.querySelector('#verifierHash')
            const verifyButton = document.querySelector('#verifyButton')
            const openedVerifierHtmlSource = document.documentElement.outerHTML
            let selectedRevisionBytes = null
            let manifestSignatureVerified = false
            let manifestSignatureDetail = tr('notVerifiedYet')

            const base64UrlToBytes = (value) => {
              const normalized = String(value || '').replace(/-/g, '+').replace(/_/g, '/')
              const padded = normalized + '='.repeat((4 - normalized.length % 4) % 4)
              const binary = atob(padded)
              const bytes = new Uint8Array(binary.length)
              for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index)
              return bytes
            }

            const bytesToBase64Url = (bytes) => {
              let binary = ''
              bytes.forEach((byte) => { binary += String.fromCharCode(byte) })
              return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '')
            }

            const pemToBytes = (pem) => {
              const base64 = String(pem || '')
                .replace(/-----BEGIN PUBLIC KEY-----/g, '')
                .replace(/-----END PUBLIC KEY-----/g, '')
                .replace(/\s/g, '')
              return base64UrlToBytes(base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, ''))
            }

            const bytesToHex = (bytes) => Array.from(bytes).map((byte) => byte.toString(16).padStart(2, '0')).join('')
            const sha256 = async (data) => new Uint8Array(await crypto.subtle.digest('SHA-256', data))
            const concatBytes = (left, right) => {
              const output = new Uint8Array(left.length + right.length)
              output.set(left, 0)
              output.set(right, left.length)
              return output
            }

            const canonicalJson = (value) => {
              if (Array.isArray(value)) return `[${value.map(canonicalJson).join(',')}]`
              if (value && typeof value === 'object') {
                return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(value[key])}`).join(',')}}`
              }
              return JSON.stringify(value)
            }

            const isValidCurp = (value) => {
              const curp = String(value || '').trim().toUpperCase()
              const pattern = /^[A-Z][AEIOUX][A-Z]{2}\d{2}(?:0[1-9]|1[0-2])(?:0[1-9]|[12]\d|3[01])[HM](?:AS|BC|BS|CC|CL|CM|CS|CH|DF|DG|GT|GR|HG|JC|MC|MN|MS|NT|NL|OC|PL|QT|QR|SP|SL|SR|TC|TS|TL|VZ|YN|ZS|NE)[B-DF-HJ-NP-TV-Z]{3}[A-J0-9]\d$/
              if (!pattern.test(curp)) return false

              const century = /\d/.test(curp[16]) ? 1900 : 2000
              const year = century + Number(curp.slice(4, 6))
              const month = Number(curp.slice(6, 8))
              const day = Number(curp.slice(8, 10))
              const date = new Date(Date.UTC(year, month - 1, day))
              if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return false

              const dictionary = '0123456789ABCDEFGHIJKLMN\u00d1OPQRSTUVWXYZ'
              let sum = 0
              for (let index = 0; index < 17; index += 1) {
                const characterValue = dictionary.indexOf(curp[index])
                if (characterValue < 0) return false
                sum += (characterValue % 10) * (18 - index)
              }
              return Number(curp[17]) === (10 - (sum % 10)) % 10
            }

            const verifyManifestSignature = async () => {
              const manifest = embeddedSignedManifest.manifest || {}
              const signature = embeddedSignedManifest.signature || {}
              const canonicalManifest = canonicalJson(manifest)
              const manifestHash = bytesToHex(await sha256(new TextEncoder().encode(canonicalManifest)))

              manifestHashElement.textContent = manifestHash
              packageSigningKeyElement.textContent = signature.publicKeySha256 || signature.keyId || tr('notConfigured')
              signedVerifierTemplateHashElement.textContent = (manifest.files || [])
                .find((file) => file.name === 'verify.html')?.sha256 || tr('notAvailable')

              if (signature.status !== 'signed') {
                manifestSignatureVerified = false
                manifestSignatureDetail = tr('unsignedManifest', { reason: signature.reason || tr('signingKeyMissing') })
                manifestSignatureStatusElement.textContent = tr('unsigned')
                packageStatus.textContent = tr('packageEvidenceUnsigned')
                packageStatus.className = 'package-status package-status--fallback'
                return
              }

              if (signature.manifestSha256 !== manifestHash) {
                manifestSignatureVerified = false
                manifestSignatureDetail = tr('manifestMismatch')
                manifestSignatureStatusElement.textContent = tr('hashMismatch')
                packageStatus.textContent = manifestSignatureDetail
                packageStatus.className = 'package-status package-status--fallback'
                return
              }

              const publicKey = await crypto.subtle.importKey(
                'spki',
                pemToBytes(signature.publicKeyPem),
                { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
                false,
                ['verify'],
              )
              manifestSignatureVerified = await crypto.subtle.verify(
                'RSASSA-PKCS1-v1_5',
                publicKey,
                base64UrlToBytes(signature.value),
                new TextEncoder().encode(canonicalManifest),
              )
              manifestSignatureDetail = manifestSignatureVerified
                ? tr('signedBy', { key: signature.keyId || tr('configuredSigningKey') })
                : tr('invalidManifestSignature')
              manifestSignatureStatusElement.textContent = manifestSignatureVerified ? tr('verifiedKey', { key: signature.keyId || tr('signed') }) : tr('failed')
              packageStatus.textContent = manifestSignatureVerified
                ? tr('packageEvidenceVerified')
                : manifestSignatureDetail
              packageStatus.className = manifestSignatureVerified ? 'package-status' : 'package-status package-status--fallback'
            }

            const derEcdsaToRaw = (signature, coordinateLength) => {
              let offset = 0
              if (signature[offset++] !== 0x30) throw new Error(tr('invalidDer'))
              let sequenceLength = signature[offset++]
              if (sequenceLength & 0x80) {
                const lengthBytes = sequenceLength & 0x7f
                sequenceLength = 0
                for (let i = 0; i < lengthBytes; i += 1) sequenceLength = (sequenceLength << 8) + signature[offset++]
              }
              const readInteger = () => {
                if (signature[offset++] !== 0x02) throw new Error(tr('invalidInteger'))
                const length = signature[offset++]
                let value = signature.slice(offset, offset + length)
                offset += length
                while (value.length > coordinateLength && value[0] === 0) value = value.slice(1)
                if (value.length > coordinateLength) throw new Error(tr('coordinateLong'))
                const padded = new Uint8Array(coordinateLength)
                padded.set(value, coordinateLength - value.length)
                return padded
              }
              return concatBytes(readInteger(), readInteger())
            }

            const importPublicKey = async (signature) => {
              const algorithm = signature.credential?.publicKeyAlgorithm
              const publicKey = base64UrlToBytes(signature.credential?.publicKey)
              if (algorithm === -7) {
                return {
                  key: await crypto.subtle.importKey('spki', publicKey, { name: 'ECDSA', namedCurve: 'P-256' }, false, ['verify']),
                  normalizeSignature: (value) => derEcdsaToRaw(value, 32),
                  verifyAlgorithm: { name: 'ECDSA', hash: 'SHA-256' },
                }
              }
              if (algorithm === -257) {
                return {
                  key: await crypto.subtle.importKey('spki', publicKey, { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' }, false, ['verify']),
                  normalizeSignature: (value) => value,
                  verifyAlgorithm: { name: 'RSASSA-PKCS1-v1_5' },
                }
              }
              throw new Error(tr('unsupportedAlgorithm', { algorithm }))
            }

            const addCheck = (checks, label, passed, detail = '', informational = false) => {
              checks.push({ label, passed, detail, informational })
              return passed
            }

            const render = (checks, error = null) => {
              const verified = !error && checks.length > 0 && checks.every((check) => check.informational || check.passed)
              output.hidden = false
              output.className = verified ? 'result' : 'result result--fail'
              output.innerHTML = `
                <h2>${verified ? tr('verificationPassed') : tr('verificationFailed')}</h2>
                <p>${tr('compareEvidence')}</p>
                ${error ? `<p class="fail">${error}</p>` : ''}
                ${checks.map((check) => `
                  <div class="check">
                    <strong>${check.label}</strong>
                    <span class="${check.informational || check.passed ? 'pass' : 'fail'}">${check.informational ? tr('info') : check.passed ? tr('ok') : tr('checkFailed')}</span>
                  </div>
                  ${check.detail ? `<pre>${check.detail}</pre>` : ''}
                `).join('')}
              `
            }

            const setSelectedRevisionFile = async (file) => {
              if (!file) return
              selectedRevisionBytes = new Uint8Array(await file.arrayBuffer())
              selectedFileName.textContent = file.name
              selectedFileName.hidden = false
              packageStatus.textContent = tr('ready', { file: file.name })
              packageStatus.className = 'package-status'
            }

            const selectedFiles = async () => {
              if (!selectedRevisionBytes) {
                throw new Error(tr('selectFileFirst'))
              }

              return {
                bundle: embeddedBundle,
                fileBytes: selectedRevisionBytes,
              }
            }

            const initializeFingerprints = async () => {
              verifierHashElement.textContent = bytesToHex(await sha256(new TextEncoder().encode(openedVerifierHtmlSource)))
              evidenceHashElement.textContent = bytesToHex(await sha256(new TextEncoder().encode(embeddedBundleSource)))
              expectedDocumentHashElement.textContent = embeddedBundle.revision?.sha256 || tr('notAvailable')
              await verifyManifestSignature()
            }

            revisionFileInput.addEventListener('change', () => {
              setSelectedRevisionFile(revisionFileInput.files[0])
            })

            dropZone.addEventListener('dragover', (event) => {
              event.preventDefault()
              dropZone.classList.add('is-dragging')
            })

            dropZone.addEventListener('dragleave', () => {
              dropZone.classList.remove('is-dragging')
            })

            dropZone.addEventListener('drop', (event) => {
              event.preventDefault()
              dropZone.classList.remove('is-dragging')
              setSelectedRevisionFile(event.dataTransfer.files[0])
            })

            verifyButton.addEventListener('click', async () => {
              const checks = []
              verifyButton.disabled = true
              try {
                const { bundle, fileBytes } = await selectedFiles()
                addCheck(checks, tr('manifestCheck'), manifestSignatureVerified, manifestSignatureDetail)
                const fileHash = bytesToHex(await sha256(fileBytes))
                const expectedOriginalHash = bundle.revision?.sha256
                const manifest = embeddedSignedManifest.manifest || {}
                const manifestFiles = Array.isArray(manifest.files) ? manifest.files : []
                const presentationFile = manifestFiles.find((file) =>
                  file.role === 'signature-presentation' &&
                  file.presentationMode === 'merged' &&
                  file.sha256 === fileHash &&
                  file.size === fileBytes.length
                )
                const isOriginalRevision = fileHash === expectedOriginalHash
                const isSignaturePresentation = Boolean(presentationFile)
                const selectedFileHashLabel = isSignaturePresentation
                  ? tr('presentationFileHash')
                  : tr('fileHash')
                addCheck(
                  checks,
                  selectedFileHashLabel,
                  isOriginalRevision || isSignaturePresentation,
                  fileHash,
                )

                const evidenceFile = manifestFiles.find((file) => file.role === 'verification-evidence')
                const embeddedEvidenceHash = bytesToHex(await sha256(new TextEncoder().encode(canonicalJson(embeddedBundle))))
                addCheck(checks, tr('evidenceManifestHash'), evidenceFile?.canonicalSha256 === embeddedEvidenceHash, embeddedEvidenceHash)
                addCheck(checks, tr('revisionManifestBinding'), manifest.document?.revisionSha256 === expectedOriginalHash, expectedOriginalHash || '')

                if (isSignaturePresentation) {
                  addCheck(checks, tr('presentationNotice'), true, tr('presentationDetail'), true)
                }

                if (!Array.isArray(bundle.signatures) || bundle.signatures.length === 0) throw new Error(tr('noSignatures'))

                for (const signature of bundle.signatures) {
                  const intent = signature.intent
                  const assertion = signature.assertion
                  if (!intent || !assertion?.response) throw new Error(tr('incompleteEvidence'))

                  const canonicalIntent = canonicalJson(intent)
                  const derivedChallenge = bytesToBase64Url(await sha256(new TextEncoder().encode(canonicalIntent)))
                  addCheck(checks, tr('canonicalIntent'), canonicalIntent === signature.canonicalIntent)
                  addCheck(checks, tr('challenge'), derivedChallenge === signature.challenge)
                  addCheck(checks, tr('revisionBinding'), intent.revisionSha256 === expectedOriginalHash && signature.documentHash === expectedOriginalHash)

                  if (intent.version === 2 && intent.signerCurp == null && signature.signedBy?.curp == null) {
                    addCheck(checks, tr('signerCurp'), true, tr('noCurp'), true)
                  } else if (intent.version === 2) {
                    addCheck(checks, tr('curpFormat'), isValidCurp(intent.signerCurp), intent.signerCurp || '')
                    addCheck(checks, tr('curpBinding'), signature.signedBy?.curp === intent.signerCurp, signature.signedBy?.curp || '')
                  } else {
                    addCheck(checks, tr('signerCurp'), true, tr('legacyCurp'), true)
                  }

                  const clientDataRaw = base64UrlToBytes(assertion.response.clientDataJSON)
                  const clientData = JSON.parse(textDecoder.decode(clientDataRaw))
                  addCheck(checks, tr('clientDataType'), clientData.type === 'webauthn.get')
                  addCheck(checks, tr('origin'), clientData.origin === intent.origin)
                  addCheck(checks, tr('clientChallenge'), clientData.challenge === derivedChallenge)

                  const authenticatorData = base64UrlToBytes(assertion.response.authenticatorData)
                  if (authenticatorData.length < 37) throw new Error(tr('authenticatorShort'))
                  const rpIdHash = authenticatorData.slice(0, 32)
                  const expectedRpIdHash = await sha256(new TextEncoder().encode(String(intent.rpId || '')))
                  addCheck(checks, tr('rpIdHash'), bytesToHex(rpIdHash) === bytesToHex(expectedRpIdHash))
                  addCheck(checks, tr('userPresence'), (authenticatorData[32] & 0x01) !== 0)

                  const expiresAt = signature.expiresAt ? new Date(signature.expiresAt) : null
                  addCheck(checks, tr('signatureExpiry'), expiresAt instanceof Date && !Number.isNaN(expiresAt.getTime()) && expiresAt.getTime() > Date.now(), signature.expiresAt || '')

                  const clientDataHash = await sha256(clientDataRaw)
                  const verificationData = concatBytes(authenticatorData, clientDataHash)
                  const signatureBytes = base64UrlToBytes(assertion.response.signature)
                  const { key, normalizeSignature, verifyAlgorithm } = await importPublicKey(signature)
                  const cryptographicSignature = await crypto.subtle.verify(
                    verifyAlgorithm,
                    key,
                    normalizeSignature(signatureBytes),
                    verificationData,
                  )
                  addCheck(checks, tr('cryptoSignature', { signer: signature.signedBy?.name || tr('unknownSigner') }), cryptographicSignature)
                }

                render(checks)
              } catch (error) {
                render(checks, error instanceof Error ? error.message : tr('unexpectedFailure'))
              } finally {
                verifyButton.disabled = false
              }
            })

            document.querySelectorAll('[data-locale]').forEach((button) => {
              button.addEventListener('click', () => {
                locale = button.dataset.locale === 'en' ? 'en' : 'es'
                try { localStorage.setItem(localeStorageKey, locale) } catch {}
                applyLocale()
                manifestSignatureDetail = tr('notVerifiedYet')
                initializeFingerprints()
                if (!output.hidden) output.hidden = true
              })
            })

            applyLocale()
            initializeFingerprints()
          </script>
        </body>
        </html>
        HTML;
    }

    private function safeFileName(string $fileName): string
    {
        $sanitized = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($fileName));

        return is_string($sanitized) && $sanitized !== '' ? $sanitized : 'revision-file.bin';
    }

    private function safeSlug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value));
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'document';
    }
}
