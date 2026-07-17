import { expect, test } from '@playwright/test'

const user = {
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
  email: 'volunteer@casamonarca.local',
  id: 8,
  name: 'Intake volunteer',
  role: 'volunteer',
}

test('official migrant intake fields submit consistent structured data', async ({ page }, testInfo) => {
  let submittedPayload: Record<string, unknown> | null = null

  await page.setViewportSize({ height: 1000, width: 1440 })
  await page.route('**/api/me', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { message: 'Current user loaded.', user } })
  })
  await page.route('**/api/csrf-token', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { csrfToken: 'visual-test-token' } })
  })
  await page.route('**/api/registry/migrants', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.fallback()
      return
    }

    submittedPayload = route.request().postDataJSON() as Record<string, unknown>
    await route.fulfill({ contentType: 'application/json', json: { data: { id: 91 } }, status: 201 })
  })

  await page.goto('/app/migrants/registry')
  await page.getByLabel('Attention date', { exact: true }).fill('2026-07-14')
  await page.getByLabel('First name (without surnames)').fill('John')
  await page.getByLabel('First last name').fill('Doe')
  await page.getByLabel('Contact phone number').fill('+52 81 3100 8716')
  await page.getByLabel('Male', { exact: true }).check()
  await page.getByLabel('Country of origin').selectOption({ label: 'Honduras' })
  await page.getByLabel('Department / state').fill('Cortes')
  await page.getByLabel('Civil status').selectOption('single')
  await page.getByLabel('Birth date').fill('1996-03-31')
  await page.getByLabel('Adult (18-59 years)', { exact: true }).check()

  await page.getByRole('button', { name: 'Submit registration' }).click()
  await expect(page.getByText('Registration submitted for review.')).toBeVisible()
  expect(submittedPayload).toMatchObject({
    payload_json: {
      attentionDate: '2026-07-14',
      birthDate: '1996-03-31',
      countryOfOrigin: 'Honduras',
      fullName: 'John Doe',
      populationGroup: 'adult',
    },
  })

  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('official-migrant-registration-form.png'),
  })
})
