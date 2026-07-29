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

const labels = (es: string, en: string) => ({ es, en, fr: es, ht: es })
const definition = {
  canonicalAnswerLocale: 'es',
  defaultLocale: 'es',
  id: 'migrant-intake-v2',
  locales: [
    { id: 'es', name: 'Español' },
    { id: 'en', name: 'English' },
    { id: 'fr', name: 'Français' },
    { id: 'ht', name: 'Kreyòl ayisyen' },
  ],
  questions: [
    {
      choices: [
        { custom: false, id: 'yes', label: labels('Sí', 'Yes'), next: { kind: 'question', questionId: 'details' }, value: 'Sí' },
        { custom: false, id: 'no', label: labels('No', 'No'), next: { kind: 'question', questionId: 'closing' }, value: 'No' },
      ],
      defaultNext: { kind: 'question', questionId: 'details' },
      help: null,
      id: 'branch',
      multiline: false,
      multipleSelection: false,
      number: 1,
      numeric: false,
      required: true,
      sectionId: 'intake',
      title: labels('¿Necesita apoyo?', 'Do you need support?'),
      type: 'choice',
    },
    {
      choices: [],
      defaultNext: { kind: 'question', questionId: 'closing' },
      help: null,
      id: 'details',
      multiline: true,
      multipleSelection: false,
      number: 2,
      numeric: false,
      required: true,
      sectionId: 'intake',
      title: labels('Describa el apoyo', 'Describe the support'),
      type: 'text',
    },
    {
      choices: [],
      defaultNext: { kind: 'end' },
      help: null,
      id: 'closing',
      multiline: true,
      multipleSelection: false,
      number: 3,
      numeric: false,
      required: false,
      sectionId: 'closing',
      title: labels('Observaciones', 'Notes'),
      type: 'text',
    },
  ],
  schemaVersion: 2,
  sections: [
    { id: 'intake', title: labels('Ingreso', 'Intake') },
    { id: 'closing', title: labels('Cierre', 'Closing') },
  ],
  summaryMappings: { firstName: 'details', firstLastName: 'missing', secondLastName: 'missing' },
  title: labels('Entrevista', 'Interview'),
}

test('translated migrant intake stores Spanish answers and submits its draft', async ({ page }, testInfo) => {
  let savedPayload = ''
  let submittedPayload = ''

  await page.setViewportSize({ height: 1000, width: 1440 })
  await page.route('**/api/me', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { message: 'Current user loaded.', user } })
  })
  await page.route('**/api/csrf-token', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { csrfToken: 'visual-test-token' } })
  })
  await page.route('**/api/registry/migrants/questionnaires/current', async (route) => {
    await route.fulfill({ contentType: 'application/json', json: { data: definition } })
  })
  await page.route('**/api/registry/migrants/drafts', async (route) => {
    if (route.request().method() === 'GET') {
      await route.fulfill({ contentType: 'application/json', json: { data: [] } })
      return
    }

    savedPayload = route.request().postData() ?? ''
    await route.fulfill({ contentType: 'application/json', json: { data: { id: 91 } }, status: 201 })
  })
  await page.route('**/api/registry/migrants/drafts/91/submit', async (route) => {
    submittedPayload = route.request().postData() ?? ''
    await route.fulfill({ contentType: 'application/json', json: { data: { id: 91 } } })
  })

  await page.goto('/app/migrants/registry')
  await page.getByLabel('Idioma de apoyo').selectOption('en')
  await page.getByLabel('Yes').check()
  await page.getByLabel('Describe the support').fill('Orientación legal')
  await page.getByRole('button', { name: 'Siguiente' }).click()
  await page.getByRole('button', { name: 'Siguiente' }).click()

  await expect(page.getByRole('heading', { name: 'Ingreso' })).toBeVisible()
  await expect(page.getByText('¿Necesita apoyo?')).toBeVisible()
  await page.getByRole('button', { name: 'Submit registration' }).evaluate((button) => button.click())
  await expect(page.getByText('Registration submitted for review.')).toBeVisible()

  expect(savedPayload).toContain('migrant-intake-v2')
  expect(savedPayload).toContain('Sí')
  expect(submittedPayload).toContain('Orientaci')

  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-registration-wizard.png'),
  })

  await page.setViewportSize({ height: 844, width: 390 })
  await expect(page.getByLabel('Idioma de apoyo')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
  await page.screenshot({
    fullPage: true,
    path: testInfo.outputPath('migrant-registration-wizard-mobile.png'),
  })
})
