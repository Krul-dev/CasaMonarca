import { expect, test, type Page } from '@playwright/test'

const admin = {
  capabilities: {
    modules: { admin: true, dashboard: true, documents: true, history: true, invites: true, logging: true, upload: true },
    security: {
      enrolled: { passkey: true, totp: true },
      enforced: false,
      isFullyEnrolled: true,
      missing: { passkey: false, totp: false },
      requires: { passkey: true, totp: true },
    },
  },
  email: 'admin@casamonarca.local',
  id: 1,
  name: 'Admin Local',
  role: 'admin',
}

const account = {
  ...admin,
  createdAt: '2026-08-01T12:00:00Z',
  curp: 'SABC560626MDFLRN01',
  devices: { count: 0, recent: [] },
  emailVerifiedAt: '2026-08-01T12:00:00Z',
  enrollment: { ...admin.capabilities.security, passkeyCount: 1 },
  lastSignInAt: '2026-08-06T18:00:00Z',
  status: 'active',
  updatedAt: '2026-08-06T18:00:00Z',
}

const revision = {
  capabilities: { canDownload: true, canManageSignaturePolicy: false, canReadVerificationBundle: true, canSign: true },
  createdAt: '2026-08-06T16:00:00Z',
  createdBy: admin,
  diffMetadata: { kind: 'initial_upload' },
  id: 901,
  mimeType: 'application/pdf',
  originalFileName: 'convenio-confidencial.pdf',
  parentRevisionId: null,
  revisionNumber: 1,
  sha256: '8d77ad67f0ef3fb7e5d5e00369ed15ba2f44dc26d98334ab7cf6c32d69fd948e',
  signaturePolicy: { requirements: [], signatureOrderEnforced: false, version: 1 },
  signatureStatus: 'unsigned',
  signatures: [],
  sizeBytes: 184320,
}

const document = {
  capabilities: { canDeleteDocument: true, canDownloadCurrent: true, canReadCurrentVerificationBundle: true, canSignCurrent: true, canUploadRevision: true },
  confidentiality: 'confidential',
  createdAt: revision.createdAt,
  currentRevision: revision,
  id: 9,
  owner: admin,
  revisions: [revision],
  status: 'active',
  title: 'Convenio confidencial',
  updatedAt: revision.createdAt,
  uploadedBy: admin,
}

const storedSignature = {
  documentHash: revision.sha256,
  expiresAt: '2027-08-06T16:00:00Z',
  id: 71,
  signatureType: 'webauthn',
  signedAt: '2026-08-06T16:30:00Z',
  signedBy: { ...admin, curp: 'SABC560626MDFLRN01' },
  verificationStatus: 'verified',
}

const signedRevision = {
  ...revision,
  capabilities: { ...revision.capabilities, canSign: false },
  signatureStatus: 'signed',
  signatures: [storedSignature],
}

const signedDocument = {
  ...document,
  currentRevision: signedRevision,
  revisions: [signedRevision],
}

async function setEnglish(page: Page) {
  await page.addInitScript(() => window.localStorage.setItem('casamonarca.locale', 'en'))
}

async function mockSession(page: Page) {
  await page.route('**/api/me', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Session authenticated.', user: admin } }))
}

test('Account Management exposes a compact CURP editor', async ({ page }, testInfo) => {
  await setEnglish(page)
  await mockSession(page)
  await page.route('**/api/admin/users?**', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Users loaded.', users: [account] } }))
  await page.route('**/api/admin/verification-package-signing-key', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Loaded.', signingKey: { configured: false, rotationSupported: true } } }))
  await page.route('**/api/admin/signing-ledger', (route) => route.fulfill({ contentType: 'application/json', json: { documents: [], message: 'Loaded.', signers: [] } }))

  await page.setViewportSize({ height: 900, width: 1280 })
  await page.goto('/app/admin?tab=accounts')

  await expect(page.getByRole('heading', { name: 'Account directory' })).toBeVisible()
  await expect(page.getByLabel('CURP')).toHaveValue('SABC560626MDFLRN01')
  await expect(page.getByRole('button', { name: 'Save CURP' })).toBeDisabled()
  await page.screenshot({ fullPage: true, path: testInfo.outputPath('account-curp-editor.png') })
})

test('invalid CURP feedback stays with the edited account', async ({ page }, testInfo) => {
  await setEnglish(page)
  await mockSession(page)
  let optionsRequests = 0
  await page.route('**/api/admin/users?**', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Users loaded.', users: [account] } }))
  await page.route('**/api/admin/verification-package-signing-key', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Loaded.', signingKey: { configured: false, rotationSupported: true } } }))
  await page.route('**/api/admin/signing-ledger', (route) => route.fulfill({ contentType: 'application/json', json: { documents: [], message: 'Loaded.', signers: [] } }))
  await page.route('**/api/admin/users/1/curp/options', (route) => {
    optionsRequests += 1
    return route.fulfill({
      contentType: 'application/json',
      json: { errors: { curp: ['The CURP format or check digit is invalid.'] }, message: 'The CURP format or check digit is invalid.' },
      status: 422,
    })
  })
  page.on('dialog', (dialog) => dialog.accept())

  await page.goto('/app/admin?tab=accounts')
  const accountCard = page.locator('.admin-user-card').filter({ hasText: account.email })
  await accountCard.getByLabel('CURP').fill('LJBV390203MBCIXM90')
  await accountCard.getByRole('button', { name: 'Save CURP' }).click()

  await expect(accountCard.getByRole('alert')).toContainText(
    'The CURP format, birth date, or check digit is invalid.',
  )
  expect(optionsRequests).toBe(0)
  await page.screenshot({ fullPage: true, path: testInfo.outputPath('account-curp-validation.png') })
})

test('document signing confirms the bound CURP before passkey authentication', async ({ page }, testInfo) => {
  await setEnglish(page)
  await mockSession(page)
  await page.route('**/api/csrf-token', (route) => route.fulfill({ contentType: 'application/json', json: { csrfToken: 'visual-csrf' } }))
  await page.route('**/api/documents', (route) => route.fulfill({ contentType: 'application/json', json: { documents: [document], message: 'Documents loaded.' } }))
  await page.route('**/api/documents/9', (route) => route.fulfill({ contentType: 'application/json', json: { document, message: 'Document loaded.' } }))
  await page.route('**/api/documents/9/verification', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Loaded.', verification: { currentRevisionId: 901, currentRevisionNumber: 1, documentId: 9, hasSignatures: false, signatureStatus: 'unsigned', signatures: [], verified: false } } }))
  await page.route('**/api/documents/9/revisions/901/sign/options', (route) => route.fulfill({
    contentType: 'application/json',
    json: {
      challengeIntent: { expiresAt: '2026-08-06T23:59:00Z', id: 'visual-intent', purpose: 'document.sign', status: 'pending' },
      message: 'Challenge created.',
      options: { allowCredentials: [], challenge: 'visual-challenge', rpId: 'localhost', timeout: 60000, userVerification: 'preferred' },
      signingTarget: { documentHash: revision.sha256, documentId: 9, expiresAt: '2026-08-06T23:59:00Z', revisionId: 901, revisionNumber: 1, signaturePolicyVersion: 1, signerCurp: 'SABC560626MDFLRN01' },
    },
  }))

  await page.setViewportSize({ height: 900, width: 1280 })
  await page.goto(`http://localhost:${process.env.PLAYWRIGHT_PORT ?? 4173}/app/documents?documentId=9&revisionId=901`)
  await page.getByRole('button', { name: 'Sign revision 1' }).click()

  const dialog = page.getByRole('dialog', { name: 'Confirm document signature' })
  await expect(dialog).toBeVisible()
  await expect(dialog.getByText('SABC560626MDFLRN01', { exact: true })).toBeVisible()
  await expect(dialog.getByText(revision.sha256, { exact: true })).toBeVisible()
  await page.screenshot({ fullPage: true, path: testInfo.outputPath('curp-signing-confirmation.png') })
})

test('signed PDF revisions expose a localized presentation download', async ({ page }, testInfo) => {
  await setEnglish(page)
  await mockSession(page)
  await page.route('**/api/documents', (route) => route.fulfill({ contentType: 'application/json', json: { documents: [signedDocument], message: 'Documents loaded.' } }))
  await page.route('**/api/documents/9', (route) => route.fulfill({ contentType: 'application/json', json: { document: signedDocument, message: 'Document loaded.' } }))
  await page.route('**/api/documents/9/verification', (route) => route.fulfill({ contentType: 'application/json', json: { message: 'Loaded.', verification: { currentRevisionId: 901, currentRevisionNumber: 1, documentId: 9, hasSignatures: true, signatureStatus: 'signed', signatures: [], verified: false } } }))
  await page.route('**/api/documents/9/revisions/901/signed-pdf?locale=en', (route) => route.fulfill({
    body: '%PDF-1.4 visual fixture',
    contentType: 'application/pdf',
    headers: {
      'Content-Disposition': 'attachment; filename="convenio-revision-1-signatures.pdf"',
      'X-CasaMonarca-Presentation-Mode': 'merged',
    },
  }))

  await page.setViewportSize({ height: 900, width: 1280 })
  await page.goto('/app/documents?documentId=9&revisionId=901')

  const button = page.getByRole('button', { name: 'Download PDF with signatures' })
  await expect(button).toBeVisible()
  await page.screenshot({ fullPage: true, path: testInfo.outputPath('document-signature-presentation.png') })

  const downloadPromise = page.waitForEvent('download')
  await button.click()
  const download = await downloadPromise
  expect(download.suggestedFilename()).toBe('convenio-revision-1-signatures.pdf')
  await expect(page.getByText('PDF with the signature record downloaded for revision 1.')).toBeVisible()
})
