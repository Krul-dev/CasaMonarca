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
