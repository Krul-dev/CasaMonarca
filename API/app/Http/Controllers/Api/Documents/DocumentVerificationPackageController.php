<?php

namespace App\Http\Controllers\Api\Documents;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Documents\DocumentAuthorizationService;
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
            $revision->load(['createdBy', 'signatures.signedBy']);
        } else {
            $document->load([
                'owner',
                'currentRevision.createdBy',
                'currentRevision.signatures.signedBy',
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
        $zipFileName = sprintf(
            '%s-revision-%s-verification-package.zip',
            $this->safeSlug($document->title),
            $revision->revision_number,
        );
        $revisionContents = Storage::disk($revision->storage_disk)->get($revision->storage_path);
        $readme = $this->readme($revisionFileName);
        $verifierTemplateSha256 = hash('sha256', $this->verifyHtmlTemplate());
        $manifest = $this->manifest(
            $document,
            $revision,
            $revisionFileName,
            $revisionContents,
            $verificationJson."\n",
            $readme,
            $verifierTemplateSha256,
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
        string $readme,
        string $verifierTemplateSha256,
    ): array {
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
            'files' => [
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
            ],
            'verification' => [
                'evidenceSha256' => hash('sha256', $verificationJson),
                'expectedDocumentSha256' => $revision->sha256,
                'signatureCount' => $revision->signatures->count(),
            ],
        ];
    }

    private function readme(string $revisionFileName): string
    {
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
        2. The SHA-256 hash of the selected revision file matches the embedded expected hash.
        3. The WebAuthn client data challenge matches the canonical signing intent.
        4. The RP ID hash in authenticator data matches the expected RP ID.
        5. The user-presence flag is set.
        6. The cryptographic signature verifies with the stored public key.
        7. The signature has not expired according to `expiresAt`.

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
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Casa Monarca Verification Package</title>
          <style>
            :root { color-scheme: light; font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; color: #13233a; background: #f3eee5; }
            body { margin: 0; padding: 2rem; }
            main { max-width: 920px; margin: 0 auto; padding: 2rem; border: 1px solid rgba(19,35,58,.12); border-radius: 24px; background: #fffaf2; box-shadow: 0 18px 48px rgba(19,35,58,.12); }
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
            <p><strong>CASA MONARCA</strong></p>
            <h1>Verification package</h1>
            <p>This page embeds the verification evidence. Drop or select the confidential revision file to hash it and verify the stored WebAuthn signature locally.</p>
            <section id="packageStatus" class="package-status">Verification evidence loaded from this HTML file.</section>
            <section class="fingerprints" aria-label="Package fingerprints">
              <div class="fingerprint-card">
                <span>Evidence hash</span>
                <code id="evidenceHash">Calculating...</code>
              </div>
              <div class="fingerprint-card">
                <span>Verifier HTML hash</span>
                <code id="verifierHash">Calculating...</code>
              </div>
              <div class="fingerprint-card">
                <span>Expected document hash</span>
                <code id="expectedDocumentHash">Not available</code>
              </div>
              <div class="fingerprint-card">
                <span>Manifest signature</span>
                <code id="manifestSignatureStatus">Checking...</code>
              </div>
              <div class="fingerprint-card">
                <span>Manifest hash</span>
                <code id="manifestHash">Calculating...</code>
              </div>
              <div class="fingerprint-card">
                <span>Package signing key</span>
                <code id="packageSigningKey">Not configured</code>
              </div>
              <div class="fingerprint-card">
                <span>Signed verifier template hash</span>
                <code id="signedVerifierTemplateHash">Not available</code>
              </div>
            </section>
            <section class="tamper-check" aria-label="Verifier tamper check">
              <h2>Verifier tamper check</h2>
              <p>Before using this standalone verifier, extract the package and run:</p>
              <pre><code>openssl dgst -sha256 -verify verify.html.public.pem -signature verify.html.signature.bin verify.html</code></pre>
              <p>Expected output: <code>Verified OK</code>. Compare the package-signing key fingerprint above with the admin-published fingerprint.</p>
            </section>

            <label id="dropZone" class="drop-zone" for="revisionFile">
              <strong>Drop the confidential revision file here</strong>
              <span>or click this area to choose it</span>
              <span id="selectedFileName" class="file-pill" hidden></span>
              <input id="revisionFile" type="file">
            </label>
            <button id="verifyButton" type="button">Verify package</button>
            <section id="output" class="result" hidden></section>
          </main>

          <script id="embeddedVerificationBundle" type="application/json">__EMBEDDED_VERIFICATION_JSON__</script>
          <script id="embeddedSignedManifest" type="application/json">__EMBEDDED_SIGNED_MANIFEST_JSON__</script>
          <script>
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
            let manifestSignatureDetail = 'Package manifest signature has not been verified yet.'

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

            const verifyManifestSignature = async () => {
              const manifest = embeddedSignedManifest.manifest || {}
              const signature = embeddedSignedManifest.signature || {}
              const canonicalManifest = canonicalJson(manifest)
              const manifestHash = bytesToHex(await sha256(new TextEncoder().encode(canonicalManifest)))

              manifestHashElement.textContent = manifestHash
              packageSigningKeyElement.textContent = signature.publicKeySha256 || signature.keyId || 'Not configured'
              signedVerifierTemplateHashElement.textContent = (manifest.files || [])
                .find((file) => file.name === 'verify.html')?.sha256 || 'Not available'

              if (signature.status !== 'signed') {
                manifestSignatureVerified = false
                manifestSignatureDetail = `Unsigned package manifest: ${signature.reason || 'package signing key not configured'}`
                manifestSignatureStatusElement.textContent = 'Unsigned'
                packageStatus.textContent = 'Verification evidence is embedded, but this package manifest is not signed by the server.'
                packageStatus.className = 'package-status package-status--fallback'
                return
              }

              if (signature.manifestSha256 !== manifestHash) {
                manifestSignatureVerified = false
                manifestSignatureDetail = 'Manifest hash does not match the signed manifest hash.'
                manifestSignatureStatusElement.textContent = 'Hash mismatch'
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
                ? `Signed by ${signature.keyId || 'configured package signing key'}`
                : 'Package manifest signature is invalid.'
              manifestSignatureStatusElement.textContent = manifestSignatureVerified ? `Verified (${signature.keyId || 'signed'})` : 'Failed'
              packageStatus.textContent = manifestSignatureVerified
                ? 'Verification evidence and package manifest signature loaded from this HTML file.'
                : manifestSignatureDetail
              packageStatus.className = manifestSignatureVerified ? 'package-status' : 'package-status package-status--fallback'
            }

            const derEcdsaToRaw = (signature, coordinateLength) => {
              let offset = 0
              if (signature[offset++] !== 0x30) throw new Error('ECDSA signature is not DER encoded.')
              let sequenceLength = signature[offset++]
              if (sequenceLength & 0x80) {
                const lengthBytes = sequenceLength & 0x7f
                sequenceLength = 0
                for (let i = 0; i < lengthBytes; i += 1) sequenceLength = (sequenceLength << 8) + signature[offset++]
              }
              const readInteger = () => {
                if (signature[offset++] !== 0x02) throw new Error('Invalid DER integer.')
                const length = signature[offset++]
                let value = signature.slice(offset, offset + length)
                offset += length
                while (value.length > coordinateLength && value[0] === 0) value = value.slice(1)
                if (value.length > coordinateLength) throw new Error('ECDSA coordinate is too long.')
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
              throw new Error(`Unsupported public key algorithm: ${algorithm}`)
            }

            const addCheck = (checks, label, passed, detail = '') => {
              checks.push({ label, passed, detail })
              return passed
            }

            const render = (checks, error = null) => {
              const verified = !error && checks.length > 0 && checks.every((check) => check.passed)
              output.hidden = false
              output.className = verified ? 'result' : 'result result--fail'
              output.innerHTML = `
                <h2>${verified ? 'Verification passed' : 'Verification failed'}</h2>
                <p>Use the fingerprints above to compare this verifier, embedded evidence, and signed manifest with a trusted copy.</p>
                ${error ? `<p class="fail">${error}</p>` : ''}
                ${checks.map((check) => `
                  <div class="check">
                    <strong>${check.label}</strong>
                    <span class="${check.passed ? 'pass' : 'fail'}">${check.passed ? 'ok' : 'failed'}</span>
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
              packageStatus.textContent = `Ready to verify ${file.name} against embedded evidence.`
              packageStatus.className = 'package-status'
            }

            const selectedFiles = async () => {
              if (!selectedRevisionBytes) {
                throw new Error('Drop or select the confidential revision file first.')
              }

              return {
                bundle: embeddedBundle,
                fileBytes: selectedRevisionBytes,
              }
            }

            const initializeFingerprints = async () => {
              verifierHashElement.textContent = bytesToHex(await sha256(new TextEncoder().encode(openedVerifierHtmlSource)))
              evidenceHashElement.textContent = bytesToHex(await sha256(new TextEncoder().encode(embeddedBundleSource)))
              expectedDocumentHashElement.textContent = embeddedBundle.revision?.sha256 || 'Not available'
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
                addCheck(checks, 'Package manifest signature', manifestSignatureVerified, manifestSignatureDetail)
                const fileHash = bytesToHex(await sha256(fileBytes))
                addCheck(checks, 'File hash', fileHash === bundle.revision?.sha256, fileHash)

                if (!Array.isArray(bundle.signatures) || bundle.signatures.length === 0) throw new Error('No signatures are present in verification.json.')

                for (const signature of bundle.signatures) {
                  const intent = signature.intent
                  const assertion = signature.assertion
                  if (!intent || !assertion?.response) throw new Error('Signature evidence is incomplete.')

                  const canonicalIntent = canonicalJson(intent)
                  const derivedChallenge = bytesToBase64Url(await sha256(new TextEncoder().encode(canonicalIntent)))
                  addCheck(checks, 'Canonical intent', canonicalIntent === signature.canonicalIntent)
                  addCheck(checks, 'Challenge', derivedChallenge === signature.challenge)
                  addCheck(checks, 'Revision hash binding', intent.revisionSha256 === fileHash && signature.documentHash === fileHash)

                  const clientDataRaw = base64UrlToBytes(assertion.response.clientDataJSON)
                  const clientData = JSON.parse(textDecoder.decode(clientDataRaw))
                  addCheck(checks, 'Client data type', clientData.type === 'webauthn.get')
                  addCheck(checks, 'Origin', clientData.origin === intent.origin)
                  addCheck(checks, 'Client challenge', clientData.challenge === derivedChallenge)

                  const authenticatorData = base64UrlToBytes(assertion.response.authenticatorData)
                  if (authenticatorData.length < 37) throw new Error('Authenticator data is too short.')
                  const rpIdHash = authenticatorData.slice(0, 32)
                  const expectedRpIdHash = await sha256(new TextEncoder().encode(String(intent.rpId || '')))
                  addCheck(checks, 'RP ID hash', bytesToHex(rpIdHash) === bytesToHex(expectedRpIdHash))
                  addCheck(checks, 'User presence', (authenticatorData[32] & 0x01) !== 0)

                  const expiresAt = signature.expiresAt ? new Date(signature.expiresAt) : null
                  addCheck(checks, 'Signature expiry', expiresAt instanceof Date && !Number.isNaN(expiresAt.getTime()) && expiresAt.getTime() > Date.now(), signature.expiresAt || '')

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
                  addCheck(checks, `Cryptographic signature by ${signature.signedBy?.name || 'unknown signer'}`, cryptographicSignature)
                }

                render(checks)
              } catch (error) {
                render(checks, error instanceof Error ? error.message : 'Verification failed unexpectedly.')
              } finally {
                verifyButton.disabled = false
              }
            })

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
