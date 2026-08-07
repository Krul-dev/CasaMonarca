import { expect, test, type Page } from '@playwright/test'

const user = {
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
      enrolled: { passkey: true, totp: true },
      enforced: false,
      isFullyEnrolled: true,
      missing: { passkey: false, totp: false },
      requires: { passkey: false, totp: false },
    },
  },
  email: 'admin@casamonarca.local',
  id: 1,
  name: 'Admin Local',
  role: 'admin',
}

const registrations = [
  {
    created_at: '2026-07-10T14:22:00.000Z',
    created_by: 2,
    created_by_role: 'volunteer',
    creator: { email: 'intake@casamonarca.local', id: 2, name: 'Intake volunteer', role: 'volunteer' },
    current_status: 'approved',
    id: 31,
    payload_json: {
      attentionDate: '2026-07-09',
      birthDate: '1991-05-12',
      civilStatus: 'single',
      countryOfOrigin: 'Honduras',
      departmentState: 'Cortes',
      firstLastName: 'Lopez',
      firstName: 'Ana',
      fullName: 'Ana Lopez X',
      gender: 'female',
      notes: 'Needs follow-up on temporary housing.',
      phone: '+52 81 0000 0000',
      populationGroup: 'adult',
      secondLastName: 'X',
    },
    updated_at: '2026-07-10T14:25:00.000Z',
  },
  {
    created_at: '2026-07-10T12:10:00.000Z',
    created_by: 3,
    created_by_role: 'non_coordinator',
    creator: { email: 'casework@casamonarca.local', id: 3, name: 'Casework', role: 'non_coordinator' },
    current_status: 'pending_approval',
    id: 32,
    payload_json: {
      attentionDate: '2026-07-10',
      birthDate: '1988-09-08',
      countryOfOrigin: 'Venezuela',
      firstLastName: 'Perez',
      firstName: 'Diego',
      fullName: 'Diego Perez',
      gender: 'man',
      populationGroup: 'migrant',
    },
    updated_at: '2026-07-10T12:10:00.000Z',
  },
]

const questionnaireDefinition = {
  canonicalAnswerLocale: 'es', defaultLocale: 'es', id: 'visual-edit',
  locales: [{ id: 'es', name: 'Español' }, { id: 'en', name: 'English' }],
  questions: [
    { choices: [], defaultNext: { kind: 'question', questionId: 'department' }, help: null, id: 'first_name', multiline: false, multipleSelection: false, number: 1, numeric: false, required: true, sectionId: 'identity', title: { en: 'First name (without surnames)', es: 'Nombre(s) sin apellidos' }, type: 'text' },
    { choices: [], defaultNext: { kind: 'end' }, help: null, id: 'department', multiline: false, multipleSelection: false, number: 2, numeric: false, required: true, sectionId: 'identity', title: { en: 'Department / state', es: 'Departamento / estado' }, type: 'text' },
  ],
  schemaVersion: 2,
  sections: [{ id: 'identity', title: { en: 'Identity', es: 'Identidad' } }],
  summaryMappings: { departmentState: 'department', firstName: 'first_name', firstLastName: 'missing_last', secondLastName: 'missing_second' },
  title: { en: 'Migrant interview', es: 'Entrevista a persona migrante' },
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => window.localStorage.setItem('casamonarca.locale', 'en'))
})

async function openRegistrations(page: Page) {
  await page.route('**/api/me', async (route) => {
    await route.fulfill({
      contentType: 'application/json',
      json: { message: 'Current user loaded.', user },
    })
  })
  await page.route('**/api/registry/migrants', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: registrations } })
  })

  await page.goto('/app/migrants/registrations')
  await expect(page.getByRole('heading', { name: 'Current migrant registrations' })).toBeVisible()
}

const nonCoordinatorUser = {
  ...user,
  email: 'reviewer@casamonarca.local',
  id: 6,
  name: 'Registry reviewer',
  role: 'non_coordinator',
}

test('filters and expands current migrant registrations', async ({ page }, testInfo) => {
  await page.setViewportSize({ height: 1000, width: 1440 })
  await openRegistrations(page)

  await expect(page.getByText('Ana Lopez X', { exact: true })).toBeVisible()
  await expect(page.getByText('Diego Perez', { exact: true })).toBeVisible()

  await page.getByLabel('Search').fill('Ana')
  await expect(page.getByText('Diego Perez', { exact: true })).toBeHidden()

  await page.locator('.registry-browser__card', { hasText: 'Ana Lopez' })
    .getByText('View registration details', { exact: true })
    .click()
  await expect(page.getByText('Needs follow-up on temporary housing.')).toBeVisible()

  await page.getByLabel('Search').fill('')
  await page.getByLabel('Status').selectOption('pending_approval')
  await expect(page.getByText('Ana Lopez X', { exact: true })).toBeHidden()
  await expect(page.getByText('Diego Perez', { exact: true })).toBeVisible()

  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-registrations-desktop.png'),
  })
})

test('keeps current registrations inside the mobile viewport', async ({ page }, testInfo) => {
  await page.setViewportSize({ height: 900, width: 390 })
  await openRegistrations(page)

  const overflow = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }))

  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 2)
  await expect(page.locator('.registry-browser__card')).toHaveCount(2)

  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-registrations-mobile.png'),
  })
})

test('non-coordinator starts an edit request from an approved registration', async ({ page }) => {
  let submittedPayload: Record<string, unknown> | null = null

  await page.route('**/api/me', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { message: 'Current user loaded.', user: nonCoordinatorUser } })
  })
  await page.route('**/api/csrf-token', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { csrfToken: 'visual-test-token' } })
  })
  await page.route('**/api/registry/migrants/31', async (route) => {
    if (route.request().method() === 'PATCH') {
      submittedPayload = route.request().postDataJSON() as Record<string, unknown>
      await route.fulfill({
        contentType: 'application/json',
        json: { data: { ...registrations[0], current_status: 'pending_review' } },
      })
      return
    }

    await route.fulfill({ contentType: 'application/json', json: { data: registrations[0] } })
  })
  await page.route('**/api/registry/migrants/questionnaires/current', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: questionnaireDefinition } })
  })
  await page.route('**/api/registry/migrants', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: registrations } })
  })

  await page.goto('/app/migrants/registrations')
  await expect(page.getByRole('button', { name: 'Request edit' })).toHaveCount(1)
  await page.getByRole('button', { name: 'Request edit' }).click()

  await expect(page.getByRole('heading', { name: 'Request registration edit' })).toBeVisible()
  await page.getByLabel('Interview support language').selectOption('en')
  await expect(page.getByLabel('First name (without surnames)')).toHaveValue('Ana')
  await page.getByLabel('Department / state').fill('Atlantida')
  await page.getByRole('button', { name: 'Next' }).click()
  await page.getByRole('button', { name: 'Submit edit request' }).click()

  await expect(page).toHaveURL(/\/app\/migrants\/registrations$/)
  expect(submittedPayload).toMatchObject({
    payload_json: {
      questionnaire: {
        answers: {
          department: { value: 'Atlantida' },
          first_name: { value: 'Ana' },
        },
        definitionId: 'visual-edit',
      },
      schemaVersion: 2,
    },
  })
})
