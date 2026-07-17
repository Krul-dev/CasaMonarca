import { expect, test } from '@playwright/test'

const coordinator = {
  capabilities: {
    modules: { admin: false, dashboard: true, documents: true, history: false, invites: true, logging: false, upload: true },
    security: { enrolled: { passkey: true, totp: true }, enforced: true, isFullyEnrolled: true, missing: { passkey: false, totp: false }, requires: { passkey: true, totp: true } },
  },
  email: 'coordinator@casamonarca.local',
  id: 2,
  name: 'Coordinator',
  role: 'coordinator',
}

const entry = {
  created_at: '2026-07-10T12:00:00Z',
  created_by: 4,
  created_by_role: 'non_coordinator',
  current_status: 'approved',
  id: 31,
  payload_json: { fullName: 'Ana Lopez' },
  updated_at: '2026-07-10T12:00:00Z',
}

test('ARCO request form only shows currently enabled rights', async ({ page }) => {
  await page.route('**/me', (route) => route.fulfill({
    contentType: 'application/json',
    json: { message: 'Current user loaded.', user: coordinator },
  }))
  await page.route('**/api/registry/migrants/arco', (route) => route.fulfill({
    contentType: 'application/json',
    json: { data: [] },
  }))
  await page.route('**/api/registry/migrants', (route) => route.fulfill({
    contentType: 'application/json',
    json: { data: [entry] },
  }))

  await page.goto('/app/migrants/arco')

  await expect(page.getByRole('heading', { name: 'ARCO rights workspace' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'ARCO Requests' })).toBeVisible()
  await expect(page.getByLabel('Right').locator('option')).toHaveCount(1)
  await expect(page.getByLabel('Right')).toHaveValue('access')
  await expect(page.getByRole('option', { name: 'Rectification' })).toHaveCount(0)
  await expect(page.getByRole('option', { name: 'Cancellation' })).toHaveCount(0)
  await expect(page.getByRole('option', { name: 'Opposition' })).toHaveCount(0)
})
