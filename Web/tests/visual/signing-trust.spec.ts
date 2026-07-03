import { expect, test, type Page } from '@playwright/test'

const adminUser = {
  capabilities: {
    modules: {
      admin: true,
      dashboard: true,
      documents: true,
      history: true,
      invites: true,
      logging: true,
      upload: true,
    },
    security: {
      enrolled: {
        passkey: true,
        totp: true,
      },
      enforced: false,
      isFullyEnrolled: true,
      missing: {
        passkey: false,
        totp: false,
      },
      requires: {
        passkey: false,
        totp: false,
      },
    },
  },
  email: 'admin@casamonarca.local',
  id: 1,
  name: 'Admin Local',
  role: 'admin',
}

const coordinatorUser = {
  ...adminUser,
  email: 'coordinator@casamonarca.local',
  id: 2,
  name: 'Coordinator Local',
  role: 'coordinator',
}

const unsignedCoordinatorUser = {
  ...adminUser,
  email: 'cedrick@casamonarca.mx',
  id: 3,
  name: 'Cedrick',
  role: 'coordinator',
}

const signedAt = '2026-04-25T22:39:00.000Z'
const expiresAt = '2027-04-25T22:39:00.000Z'
const unexpectedBrowserFailures = new WeakMap<Page, string[]>()

const signature = (
  id: number,
  signedBy: typeof adminUser,
  hash: string,
  signedAtOverride = signedAt,
) => ({
  credential: {
    id: `credential-${signedBy.id}`,
    name: `${signedBy.name} key`,
    publicKeyFingerprintSha256: `fingerprint-${signedBy.id}`,
  },
  expiresAt,
  id,
  signedAt: signedAtOverride,
  signedBy,
  signatureHash: hash,
  signatureType: 'passkey',
  verificationStatus: 'verified',
})

const revision = (
  id: number,
  revisionNumber: number,
  originalFileName: string,
  sha256: string,
  signatures: ReturnType<typeof signature>[],
) => ({
  id,
  originalFileName,
  revisionNumber,
  sha256,
  signatureStatus: signatures.length > 0 ? 'signed' : 'unsigned',
  signatures,
})

const signingLedgerResponse = {
  documents: [
    {
      id: 9,
      revisions: [
        revision(901, 1, 'image(1)(1)(1)(1)(1).jpg', '88989c9935492302', [
          signature(21, adminUser, '88989c9935492302'),
        ]),
      ],
      status: 'active',
      title: 'image(1)(1)(1)(1)(1)',
    },
    {
      id: 7,
      revisions: [
        revision(703, 3, 'research-dossier-for-team-1-visualization-and-technology-1-1-docx-revision-4-verification-bundle(1).json', '73a961c35e7f5a53', [
          signature(11, adminUser, '73a961c35e7f5a53'),
          signature(12, coordinatorUser, '73a961c35e7f5a53'),
        ]),
        revision(702, 2, 'sistema_rsa-3.pdf', 'a6512bbffdf24df3', []),
        revision(701, 1, 'WhatsApp Image 2026-04-18 at 4.29.57 PM.jpeg', '347d6a8ebb67fd03', [
          signature(13, adminUser, '347d6a8ebb67fd03', '2026-04-25T22:21:00.000Z'),
          signature(14, coordinatorUser, '347d6a8ebb67fd03', '2026-04-25T22:46:00.000Z'),
        ]),
      ],
      status: 'active',
      title: 'WhatsApp Image 2026-04-18 at 4.29.57 PM',
    },
    {
      id: 8,
      revisions: [
        revision(801, 1, 'WhatsApp Image 2026-04-15 at 9.24.12 PM.pdf', '4a07f79ad271647d', [
          signature(15, adminUser, '4a07f79ad271647d'),
          signature(16, coordinatorUser, '4a07f79ad271647d'),
        ]),
      ],
      status: 'active',
      title: 'WhatsApp Image 2026-04-15 at 9.24.12 PM',
    },
  ],
  message: 'Signing ledger loaded.',
  signers: [
    {
      documents: [],
      email: adminUser.email,
      id: adminUser.id,
      name: adminUser.name,
      role: adminUser.role,
      signatureCount: 4,
    },
    {
      documents: [],
      email: coordinatorUser.email,
      id: coordinatorUser.id,
      name: coordinatorUser.name,
      role: coordinatorUser.role,
      signatureCount: 3,
    },
    {
      documents: [],
      email: unsignedCoordinatorUser.email,
      id: unsignedCoordinatorUser.id,
      name: unsignedCoordinatorUser.name,
      role: unsignedCoordinatorUser.role,
      signatureCount: 0,
    },
  ],
}

const linkedDocumentRevision = {
  capabilities: {
    canDownload: true,
    canReadVerificationBundle: true,
    canSign: false,
  },
  createdAt: signedAt,
  createdBy: adminUser,
  id: 901,
  originalFileName: 'image(1)(1)(1)(1)(1).jpg',
  mimeType: 'image/jpeg',
  parentRevisionId: null,
  revisionNumber: 1,
  sha256: '88989c9935492302',
  signatureStatus: 'signed',
  signatures: [signature(21, adminUser, '88989c9935492302')],
  sizeBytes: 1200,
}

const linkedDocument = {
  capabilities: {
    canDeleteDocument: true,
    canDownloadCurrent: true,
    canReadCurrentVerificationBundle: true,
    canSignCurrent: true,
    canUploadRevision: true,
  },
  confidentiality: 'confidential',
  createdAt: signedAt,
  currentRevision: linkedDocumentRevision,
  id: 9,
  owner: adminUser,
  revisions: [linkedDocumentRevision],
  status: 'active',
  title: 'image(1)(1)(1)(1)(1)',
  updatedAt: signedAt,
  uploadedBy: adminUser,
}

test.beforeEach(({ page }) => {
  const failures: string[] = []
  unexpectedBrowserFailures.set(page, failures)

  page.on('console', (message) => {
    if (message.type() === 'error') {
      failures.push(`console error: ${message.text()}`)
    }
  })

  page.on('pageerror', (error) => {
    failures.push(`page error: ${error.message}`)
  })

  page.on('response', (response) => {
    if (response.url().includes('/api/') && response.status() >= 500) {
      failures.push(`API ${response.status()}: ${response.url()}`)
    }
  })
})

test.afterEach(({ page }) => {
  expect(unexpectedBrowserFailures.get(page) ?? []).toEqual([])
})

async function mockAdminApi(page: Page) {
  await page.route('**/api/me', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: {
        message: 'Current user loaded.',
        user: adminUser,
      },
    })
  })

  await page.route('**/api/admin/users?**', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: {
        message: 'Users loaded.',
        users: [],
      },
    })
  })

  await page.route('**/api/admin/verification-package-signing-key', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: {
        message: 'Signing key loaded.',
        signingKey: {
          algorithm: 'RS256',
          configCached: false,
          configured: true,
          envWritable: true,
          keyId: 'visual-qa',
          privateKeyConfigured: true,
          publicKeyConfigured: true,
          publicKeyFingerprint: 'aa:bb:cc:dd',
          rotationSupported: true,
        },
      },
    })
  })

  await page.route('**/api/admin/signing-ledger', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: signingLedgerResponse,
    })
  })

  await page.route('**/api/documents', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: {
        documents: [linkedDocument],
        message: 'Documents loaded.',
      },
    })
  })

  await page.route('**/api/documents/9', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: {
        document: linkedDocument,
        message: 'Document loaded.',
      },
    })
  })

  await page.route('**/api/documents/9/verification', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: {
        message: 'Document verification loaded.',
        verification: {
          currentRevisionId: 901,
          currentRevisionNumber: 1,
          documentId: 9,
          hasSignatures: true,
          signatureStatus: 'signed',
          signatures: [signature(21, adminUser, '88989c9935492302')],
          verified: true,
        },
      },
    })
  })
}

async function openSigningTrust(page: Page) {
  await mockAdminApi(page)
  await page.goto('/app/admin')
  await page.getByRole('button', { name: 'Signing Trust' }).click()
  await expect(page.getByRole('heading', { name: 'Signing Trust' })).toBeVisible()
  await expect(page.locator('.signing-ledger')).toBeVisible()
}

test.describe('Signing Trust visual QA', () => {
  test('desktop graph nodes and connectors stay inside the graph', async ({ page }, testInfo) => {
    await page.setViewportSize({ height: 1000, width: 1500 })
    await openSigningTrust(page)

    const graph = page.locator('.signing-ledger__graph')
    await expect(graph).toBeVisible()
    await expect(page.locator('.signing-ledger__compact')).toBeHidden()

    const geometry = await graph.evaluate((graphElement) => {
      const body = graphElement.querySelector('.signing-ledger__graph-body')

      if (!(body instanceof HTMLElement)) {
        throw new Error('Signing graph body was not rendered.')
      }

      const bodyRect = body.getBoundingClientRect()
      const margin = 4
      const nodeSelectors = [
        '.signing-ledger__user-node',
        '.signing-ledger__key-node',
        '.signing-ledger__revision-node',
      ]
      const nodeBoxes = nodeSelectors.flatMap((selector) =>
        Array.from(body.querySelectorAll(selector)).map((node) => {
          const rect = node.getBoundingClientRect()

          return {
            height: rect.height,
            left: rect.left,
            selector,
            text: node.textContent?.replace(/\s+/g, ' ').trim() ?? '',
            top: rect.top,
            width: rect.width,
          }
        }),
      )
      const offscreenNodes = nodeBoxes.filter((box) =>
        box.width <= 0 ||
        box.height <= 0 ||
        box.left < bodyRect.left - margin ||
        box.top < bodyRect.top - margin ||
        box.left + box.width > bodyRect.right + margin ||
        box.top + box.height > bodyRect.bottom + margin,
      )
      const userAndKeyBoxes = nodeBoxes.filter((box) =>
        box.selector === '.signing-ledger__user-node' ||
        box.selector === '.signing-ledger__key-node',
      )
      const overlappingNodes: Array<{ first: string, second: string }> = []

      for (let firstIndex = 0; firstIndex < userAndKeyBoxes.length; firstIndex += 1) {
        for (let secondIndex = firstIndex + 1; secondIndex < userAndKeyBoxes.length; secondIndex += 1) {
          const first = userAndKeyBoxes[firstIndex]
          const second = userAndKeyBoxes[secondIndex]
          const xOverlap = Math.max(0, Math.min(first.left + first.width, second.left + second.width) - Math.max(first.left, second.left))
          const yOverlap = Math.max(0, Math.min(first.top + first.height, second.top + second.height) - Math.max(first.top, second.top))

          if (xOverlap * yOverlap > 1) {
            overlappingNodes.push({
              first: first.text,
              second: second.text,
            })
          }
        }
      }

      const invalidConnectorPaths = Array.from(body.querySelectorAll('.signing-ledger__connector')).flatMap((connector) => {
        const d = connector.getAttribute('d') ?? ''
        const numbers = d.match(/-?\d+(?:\.\d+)?/g)?.map(Number) ?? []

        if (numbers.length < 8) {
          return [{ d, reason: 'missing path coordinates' }]
        }

        const startX = numbers[0]
        const startY = numbers[1]
        const endX = numbers[numbers.length - 2]
        const endY = numbers[numbers.length - 1]
        const points = [
          [startX, startY],
          [endX, endY],
        ]
        const outOfBounds = points.some(([x, y]) =>
          x < -margin ||
          y < -margin ||
          x > bodyRect.width + margin ||
          y > bodyRect.height + margin,
        )

        return outOfBounds ? [{ d, reason: 'endpoint outside graph body' }] : []
      })
      const nodePoint = (selector: string, side: 'left' | 'right') => {
        const node = body.querySelector(selector)

        if (!(node instanceof HTMLElement)) {
          return null
        }

        const rect = node.getBoundingClientRect()

        return {
          x: side === 'left' ? rect.left - bodyRect.left : rect.right - bodyRect.left,
          y: rect.top + rect.height / 2 - bodyRect.top,
        }
      }
      const distance = (
        first: { x: number, y: number },
        second: { x: number, y: number },
      ) => Math.hypot(first.x - second.x, first.y - second.y)
      const invalidConnectorAttachments = Array.from(body.querySelectorAll('.signing-ledger__connector')).flatMap((connector) => {
        const d = connector.getAttribute('d') ?? ''
        const numbers = d.match(/-?\d+(?:\.\d+)?/g)?.map(Number) ?? []
        const connectorId = connector.getAttribute('data-connector-id') ?? ''

        if (numbers.length < 8 || !connectorId) {
          return []
        }

        const start = {
          x: numbers[0],
          y: numbers[1],
        }
        const end = {
          x: numbers[numbers.length - 2],
          y: numbers[numbers.length - 1],
        }
        const tolerance = 8

        if (connectorId.startsWith('user-key:')) {
          const signerId = connectorId.replace('user-key:', '')
          const userPoint = nodePoint(`.signing-ledger__user-node[data-signer-id="${signerId}"]`, 'right')
          const keyPoint = nodePoint(`.signing-ledger__key-node[data-signer-id="${signerId}"]`, 'left')

          if (!userPoint || !keyPoint) {
            return [{ connectorId, reason: 'missing user or key node' }]
          }

          if (distance(start, userPoint) > tolerance || distance(end, keyPoint) > tolerance) {
            return [{ connectorId, end, keyPoint, reason: 'user-key path is detached', start, userPoint }]
          }

          return []
        }

        if (connectorId.startsWith('key-revision:')) {
          const [, signerId, documentId, revisionId] = connectorId.split(':')
          const keyPoint = nodePoint(`.signing-ledger__key-node[data-signer-id="${signerId}"]`, 'right')
          const revisionPoint = nodePoint(`.signing-ledger__revision-node[data-revision-id="${documentId}:${revisionId}"]`, 'left')

          if (!keyPoint || !revisionPoint) {
            return [{ connectorId, reason: 'missing key or revision node' }]
          }

          if (distance(start, keyPoint) > tolerance || distance(end, revisionPoint) > tolerance) {
            return [{ connectorId, end, keyPoint, reason: 'key-revision path is detached', revisionPoint, start }]
          }
        }

        return []
      })

      return {
        bodyHeight: bodyRect.height,
        bodyWidth: bodyRect.width,
        connectorCount: body.querySelectorAll('.signing-ledger__connector').length,
        invalidConnectorAttachments,
        invalidConnectorPaths,
        nodeCount: nodeBoxes.length,
        offscreenNodes,
        overlappingNodes,
      }
    })

    expect(geometry.nodeCount).toBeGreaterThan(0)
    expect(geometry.connectorCount).toBeGreaterThan(0)
    expect(geometry.offscreenNodes).toEqual([])
    expect(geometry.overlappingNodes).toEqual([])
    expect(geometry.invalidConnectorPaths).toEqual([])
    expect(geometry.invalidConnectorAttachments).toEqual([])

    await page.screenshot({
      fullPage: true,
      path: testInfo.outputPath('signing-trust-desktop.png'),
    })
  })

  test('constrained desktop graph scrolls horizontally inside the panel', async ({ page }, testInfo) => {
    await page.setViewportSize({ height: 1000, width: 1100 })
    await openSigningTrust(page)

    const scroller = page.locator('.signing-ledger__graph-scroll')
    await expect(scroller).toBeVisible()
    await expect(page.locator('.signing-ledger__graph')).toBeVisible()

    const scrollMetrics = await scroller.evaluate((element) => ({
      clientWidth: element.clientWidth,
      scrollWidth: element.scrollWidth,
    }))
    expect(scrollMetrics.scrollWidth).toBeGreaterThan(scrollMetrics.clientWidth + 20)

    await scroller.evaluate((element) => {
      element.scrollLeft = element.scrollWidth
    })

    const scrolledState = await scroller.evaluate((element) => {
      const scrollerRect = element.getBoundingClientRect()
      const lastDocument = element.querySelector('.signing-ledger__document-group:last-child')

      if (!(lastDocument instanceof HTMLElement)) {
        throw new Error('No document group was rendered.')
      }

      const documentRect = lastDocument.getBoundingClientRect()

      return {
        lastDocumentVisible:
          documentRect.left < scrollerRect.right &&
          documentRect.right > scrollerRect.left,
        scrollLeft: element.scrollLeft,
      }
    })
    expect(scrolledState.scrollLeft).toBeGreaterThan(0)
    expect(scrolledState.lastDocumentVisible).toBe(true)

    const pageOverflow = await page.evaluate(() => ({
      clientWidth: document.documentElement.clientWidth,
      scrollWidth: document.documentElement.scrollWidth,
    }))
    expect(pageOverflow.scrollWidth).toBeLessThanOrEqual(pageOverflow.clientWidth + 2)

    await page.screenshot({
      fullPage: true,
      path: testInfo.outputPath('signing-trust-constrained-scroll.png'),
    })
  })

  test('revision links navigate to the matching document revision', async ({ page }) => {
    await page.setViewportSize({ height: 1000, width: 1500 })
    await openSigningTrust(page)

    await page.locator('.signing-ledger__revision-link').first().click()

    await expect(page).toHaveURL(/\/app\/documents\?documentId=9&revisionId=901$/)
  })

  for (const viewport of [
    { height: 1000, name: 'tablet', width: 900 },
    { height: 900, name: 'mobile', width: 390 },
  ]) {
    test(`${viewport.name} uses compact ledger instead of graph`, async ({ page }, testInfo) => {
      await page.setViewportSize({ height: viewport.height, width: viewport.width })
      await openSigningTrust(page)

      await expect(page.locator('.signing-ledger__graph')).toBeHidden()
      await expect(page.locator('.signing-ledger__compact')).toBeVisible()
      await expect(page.locator('.signing-ledger__compact-revision summary')).toHaveCount(5)

      const overflow = await page.evaluate(() => ({
        clientWidth: document.documentElement.clientWidth,
        scrollWidth: document.documentElement.scrollWidth,
      }))
      expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 2)

      await page.screenshot({
        fullPage: true,
        path: testInfo.outputPath(`signing-trust-${viewport.name}.png`),
      })
    })
  }
})
