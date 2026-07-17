import { expect, test, type Page } from '@playwright/test'

const nonCoordinatorUser = {
  capabilities: {
    modules: {
      admin: false,
      dashboard: true,
      documents: true,
      history: false,
      invites: false,
      logging: false,
      upload: true,
    },
    security: {
      enrolled: { passkey: false, totp: true },
      enforced: true,
      isFullyEnrolled: true,
      missing: { passkey: false, totp: false },
      requires: { passkey: false, totp: true },
    },
  },
  email: 'reviewer@casamonarca.local',
  id: 4,
  name: 'Registry reviewer',
  role: 'non_coordinator',
}

const reviewEntry = {
  created_at: '2026-07-10T15:00:00.000Z',
  created_by: 9,
  created_by_role: 'volunteer',
  creator: { email: 'intake@casamonarca.local', id: 9, name: 'Intake', role: 'volunteer' },
  current_status: 'pending_review',
  id: 44,
  pending_action: 'create',
  payload_json: {
    attentionDate: '2026-07-10',
    birthDate: '1994-01-01',
    countryOfOrigin: 'Guatemala',
    firstLastName: 'Ramirez',
    firstName: 'Sofia',
    fullName: 'Sofia Ramirez',
    gender: 'woman',
    populationGroup: 'migrant',
  },
  updated_at: '2026-07-10T15:00:00.000Z',
}

const coordinatorUser = {
  ...nonCoordinatorUser,
  capabilities: {
    ...nonCoordinatorUser.capabilities,
    security: {
      ...nonCoordinatorUser.capabilities.security,
      enrolled: { passkey: true, totp: true },
      requires: { passkey: true, totp: true },
    },
  },
  email: 'coordinator@casamonarca.local',
  id: 2,
  name: 'Registry coordinator',
  role: 'coordinator',
}

const approvalEntries = [
  {
    ...reviewEntry,
    current_status: 'pending_approval',
    id: 51,
    payload_json: { ...reviewEntry.payload_json, fullName: 'Sofia Ramirez' },
    updated_at: '2026-07-11T15:00:00.000Z',
  },
  {
    ...reviewEntry,
    current_status: 'pending_approval',
    id: 52,
    pending_action: 'update',
    pending_payload_json: {
      ...reviewEntry.payload_json,
      countryOfOrigin: 'Honduras',
      fullName: 'Maria Lopez',
    },
    payload_json: { ...reviewEntry.payload_json, fullName: 'Maria L.' },
    updated_at: '2026-07-12T17:30:00.000Z',
  },
  {
    ...reviewEntry,
    current_status: 'pending_approval',
    id: 53,
    payload_json: { ...reviewEntry.payload_json, fullName: 'Daniel Ortiz' },
    updated_at: '2026-07-13T10:15:00.000Z',
  },
]

async function openReviewQueue(page: Page) {
  await page.route('**/api/me', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { message: 'Current user loaded.', user: nonCoordinatorUser } })
  })
  await page.route('**/api/registry/migrants/pending-review', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: [reviewEntry] } })
  })
  await page.route('**/api/registry/migrants/corrections', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: [] } })
  })

  await page.goto('/app/migrants/approvals')
  await expect(page.getByRole('heading', { name: 'Registrations pending review' })).toBeVisible()
}

test('non-coordinator can access review but not final approval', async ({ page }, testInfo) => {
  await page.setViewportSize({ height: 900, width: 1280 })
  await openReviewQueue(page)

  await expect(page.getByText('Sofia Ramirez', { exact: true })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Registrations pending final approval' })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Forward to approval' })).toBeVisible()

  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-review-approval.png'),
  })
})

test('coordinator can filter and select final approvals in bulk', async ({ page }, testInfo) => {
  await page.setViewportSize({ height: 1000, width: 1440 })
  await page.route('**/api/me', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { message: 'Current user loaded.', user: coordinatorUser } })
  })
  await page.route('**/api/registry/migrants/pending-review', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: [] } })
  })
  await page.route('**/api/registry/migrants/pending-approval', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: approvalEntries } })
  })

  await page.goto('/app/migrants/approvals')
  await expect(page.getByRole('heading', { name: 'Registrations pending final approval' })).toBeVisible()
  await expect(page.getByText('Select all filtered (3)')).toBeVisible()

  await page.getByLabel('Queued from').fill('2026-07-12')
  await expect(page.getByText('Select all filtered (2)')).toBeVisible()

  await page.getByLabel('Request type').selectOption('update')
  await expect(page.getByText('Select all filtered (1)')).toBeVisible()
  await expect(page.getByText('Maria Lopez', { exact: true })).toBeVisible()
  await expect(page.getByText('Sofia Ramirez', { exact: true })).toHaveCount(0)

  await page.getByLabel('Select all filtered (1)').check()
  await expect(page.getByRole('button', { name: 'Approve selected (1)' })).toBeEnabled()
  await expect(page.getByText('1 selected')).toBeVisible()

  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-bulk-approval.png'),
  })

  await page.setViewportSize({ height: 900, width: 390 })
  await expect.poll(async () => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-bulk-approval-mobile.png'),
  })
})
