import { readFileSync } from 'node:fs'
import { createHash } from 'node:crypto'
import { resolve } from 'node:path'

import { expect, test } from '@playwright/test'

const controllerSource = readFileSync(
  resolve(process.cwd(), '../API/app/Http/Controllers/Api/Documents/DocumentVerificationPackageController.php'),
  'utf8',
)
const template = controllerSource.match(/return <<<'HTML'\n([\s\S]*?)\n\s*HTML;/)?.[1]

if (!template) throw new Error('Offline verification HTML template was not found.')

const verifierHtml = template
  .replace('__EMBEDDED_VERIFICATION_JSON__', JSON.stringify({ revision: { sha256: 'demo-hash' }, signatures: [] }))
  .replace('__EMBEDDED_SIGNED_MANIFEST_JSON__', JSON.stringify({ manifest: { files: [] }, signature: { reason: 'demo', status: 'unsigned' } }))

const canonicalJson = (value: unknown): string => {
  if (Array.isArray(value)) return `[${value.map(canonicalJson).join(',')}]`
  if (value && typeof value === 'object') {
    const record = value as Record<string, unknown>
    return `{${Object.keys(record).sort().map((key) => `${JSON.stringify(key)}:${canonicalJson(record[key])}`).join(',')}}`
  }
  return JSON.stringify(value)
}

const sha256 = (value: string | Buffer) => createHash('sha256').update(value).digest()
const base64Url = (value: string | Buffer) => Buffer.from(value).toString('base64url')

test('offline verifier switches languages and remembers the selection', async ({ page }) => {
  await page.route('**/offline-verifier.html', (route) => route.fulfill({
    body: verifierHtml,
    contentType: 'text/html',
  }))

  await page.goto('/offline-verifier.html')

  await expect(page.getByRole('heading', { name: 'Paquete de verificación' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'ES', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByText('Firma del manifiesto', { exact: true })).toBeVisible()

  await page.getByRole('button', { name: 'EN', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Verification package' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'EN', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await expect(page.getByText('Manifest signature', { exact: true })).toBeVisible()

  await page.reload()
  await expect(page.getByRole('heading', { name: 'Verification package' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'EN', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await page.screenshot({ fullPage: true, path: 'test-results/visual/offline-verifier-language.png' })
})

test('offline verifier accepts the manifest-backed PDF with signature header', async ({ page }) => {
  const originalHash = '8d77ad67f0ef3fb7e5d5e00369ed15ba2f44dc26d98334ab7cf6c32d69fd948e'
  const presentationBytes = Buffer.from('%PDF-1.4 presentation-with-signature-header')
  const intent = {
    documentId: 9,
    expiresAt: '2099-08-07T12:00:00Z',
    issuedAt: '2026-08-07T12:00:00Z',
    nonce: 'offline-presentation-test',
    origin: 'https://verify.example.test',
    purpose: 'document-sign',
    revisionId: 901,
    revisionNumber: 1,
    revisionSha256: originalHash,
    rpId: 'verify.example.test',
    signaturePolicyVersion: 1,
    userId: 1,
    version: 1,
  }
  const canonicalIntent = canonicalJson(intent)
  const challenge = base64Url(sha256(canonicalIntent))
  const clientData = base64Url(JSON.stringify({ challenge, origin: intent.origin, type: 'webauthn.get' }))
  const authenticatorData = Buffer.concat([
    sha256(intent.rpId),
    Buffer.from([1]),
    Buffer.alloc(4),
  ])
  const bundle = {
    revision: { sha256: originalHash },
    signatures: [{
      assertion: { response: { authenticatorData: base64Url(authenticatorData), clientDataJSON: clientData, signature: base64Url('signature') } },
      canonicalIntent,
      challenge,
      credential: { publicKey: base64Url('public-key'), publicKeyAlgorithm: -257 },
      documentHash: originalHash,
      expiresAt: '2099-08-07T12:00:00Z',
      intent,
      signedBy: { curp: null, name: 'Admin Local' },
    }],
  }
  const bundleSource = JSON.stringify(bundle)
  const manifest = {
    document: { revisionSha256: originalHash },
    files: [
      { canonicalSha256: sha256(canonicalJson(bundle)).toString('hex'), name: 'verification.json', role: 'verification-evidence', sha256: sha256(bundleSource).toString('hex'), size: Buffer.byteLength(bundleSource) },
      { name: 'signed-copy.pdf', presentationMode: 'merged', role: 'signature-presentation', sha256: sha256(presentationBytes).toString('hex'), size: presentationBytes.length },
    ],
  }
  const signedManifest = {
    manifest,
    signature: {
      keyId: 'visual-package-key',
      manifestSha256: sha256(canonicalJson(manifest)).toString('hex'),
      publicKeyPem: '-----BEGIN PUBLIC KEY-----\nAA==\n-----END PUBLIC KEY-----',
      status: 'signed',
      value: base64Url('manifest-signature'),
    },
  }
  const presentationVerifierHtml = template
    .replace('__EMBEDDED_VERIFICATION_JSON__', bundleSource)
    .replace('__EMBEDDED_SIGNED_MANIFEST_JSON__', JSON.stringify(signedManifest))

  await page.addInitScript(() => {
    Object.defineProperty(SubtleCrypto.prototype, 'importKey', { configurable: true, value: async () => ({}) })
    Object.defineProperty(SubtleCrypto.prototype, 'verify', { configurable: true, value: async () => true })
  })
  await page.route('**/presentation-verifier.html', (route) => route.fulfill({ body: presentationVerifierHtml, contentType: 'text/html' }))
  await page.goto('/presentation-verifier.html')
  await page.getByLabel('Arrastra la versión original o el PDF con encabezado de firmas').setInputFiles({
    buffer: presentationBytes,
    mimeType: 'application/pdf',
    name: 'signed-copy.pdf',
  })
  await page.getByRole('button', { name: 'Verificar paquete' }).click()

  await expect(page.getByRole('heading', { name: 'Verificación correcta' })).toBeVisible()
  await expect(page.getByText('Hash del PDF de consulta en el manifiesto', { exact: true })).toBeVisible()
  await expect(page.getByText('Límite de confianza del PDF de consulta', { exact: true })).toBeVisible()
})
