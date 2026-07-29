import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as registry from '../../lib/registry'
import type { MigrantQuestionnaireDefinition } from '../../types/registry'
import { MigrantRegistryForm } from './MigrantRegistryForm'

const labels = (es: string, en: string) => ({ es, en, fr: es, ht: es })
const definition: MigrantQuestionnaireDefinition = {
  canonicalAnswerLocale: 'es',
  defaultLocale: 'es',
  id: 'migrant-intake-v2',
  locales: [{ id: 'es', name: 'Español' }, { id: 'en', name: 'English' }, { id: 'fr', name: 'Français' }, { id: 'ht', name: 'Kreyòl ayisyen' }],
  questions: [
    {
      choices: [
        { custom: false, id: 'yes', label: labels('Sí', 'Yes'), next: { kind: 'question', questionId: 'details' }, value: 'Sí' },
        { custom: false, id: 'no', label: labels('No', 'No'), next: { kind: 'question', questionId: 'closing' }, value: 'No' },
      ],
      defaultNext: { kind: 'question', questionId: 'details' }, help: null, id: 'branch', multiline: false,
      multipleSelection: false, number: 1, numeric: false, required: true, sectionId: 'intake', title: labels('¿Necesita apoyo?', 'Do you need support?'), type: 'choice',
    },
    { choices: [], defaultNext: { kind: 'question', questionId: 'closing' }, help: null, id: 'details', multiline: true, multipleSelection: false, number: 2, numeric: false, required: true, sectionId: 'intake', title: labels('Detalles', 'Details'), type: 'text' },
    { choices: [], defaultNext: { kind: 'end' }, help: null, id: 'closing', multiline: true, multipleSelection: false, number: 3, numeric: false, required: false, sectionId: 'closing', title: labels('Observaciones', 'Notes'), type: 'text' },
  ],
  schemaVersion: 2,
  sections: [{ id: 'intake', title: labels('Ingreso', 'Intake') }, { id: 'closing', title: labels('Cierre', 'Closing') }],
  summaryMappings: { firstName: 'details', firstLastName: 'missing', secondLastName: 'missing' },
  title: labels('Entrevista', 'Interview'),
}

const definitionWithAttentionDate: MigrantQuestionnaireDefinition = {
  ...definition,
  questions: [
    {
      choices: [], defaultNext: { kind: 'question', questionId: 'branch' }, help: null, id: 'attention',
      multiline: false, multipleSelection: false, number: 1, numeric: false, required: true,
      sectionId: 'intake', title: labels('Fecha de atención', 'Attention date'), type: 'date',
    },
    ...definition.questions.map((question) => ({ ...question, number: question.number + 1 })),
  ],
  summaryMappings: { ...definition.summaryMappings, attentionDate: 'attention' },
}

describe('MigrantRegistryForm', () => {
  beforeEach(() => {
    vi.spyOn(registry, 'getCurrentMigrantQuestionnaire').mockResolvedValue({ data: definition })
  })

  it('uses translated prompts while storing canonical Spanish answers and following branches', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn().mockResolvedValue(undefined)
    render(<MigrantRegistryForm documentsEnabled={false} onSubmit={onSubmit} />)

    await user.selectOptions(await screen.findByLabelText('Idioma de apoyo'), 'en')
    await user.click(screen.getByLabelText('Yes'))
    await user.type(screen.getByLabelText('Details'), 'Respuesta en español')
    await user.click(screen.getByRole('button', { name: 'Siguiente' }))
    await user.click(screen.getByRole('button', { name: 'Siguiente' }))
    expect(screen.getByText('Paso 3 de 3')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Enviar registro' }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0][0].questionnaire.answers).toEqual({
      branch: { value: 'Sí' },
      details: { value: 'Respuesta en español' },
    })
    expect(onSubmit.mock.calls[0][0]).not.toHaveProperty('locale')
  })

  it('uploads supporting documents only from the final review step', async () => {
    const user = userEvent.setup()
    const onSubmit = vi.fn().mockResolvedValue(undefined)
    const document = new File(['document'], 'identification.pdf', { type: 'application/pdf' })
    render(<MigrantRegistryForm onSubmit={onSubmit} />)

    await user.click(await screen.findByLabelText('No'))
    await user.click(screen.getByRole('button', { name: 'Siguiente' }))
    await user.click(screen.getByRole('button', { name: 'Siguiente' }))
    await user.upload(screen.getByLabelText('Agregar documentos al envío'), document)
    await user.click(screen.getByRole('button', { name: 'Enviar registro' }))

    await waitFor(() => expect(onSubmit).toHaveBeenCalledTimes(1))
    expect(onSubmit.mock.calls[0][1]).toEqual([{ file: document, label: '' }])
  })

  it('creates a server draft after the first meaningful answer', async () => {
    const user = userEvent.setup()
    const onSaveDraft = vi.fn().mockResolvedValue({ id: 42 })
    render(<MigrantRegistryForm draftsEnabled onSaveDraft={onSaveDraft} onSubmit={vi.fn()} />)

    await user.click(await screen.findByLabelText('No'))
    await waitFor(() => expect(onSaveDraft).toHaveBeenCalledTimes(1), { timeout: 2000 })
    expect(onSaveDraft.mock.calls[0][0].questionnaire.answers.branch.value).toBe('No')
    expect(onSaveDraft.mock.calls[0][1]).toBeNull()
  })

  it('defaults the attention date to the local date when registration starts', async () => {
    vi.mocked(registry.getCurrentMigrantQuestionnaire).mockResolvedValueOnce({ data: definitionWithAttentionDate })
    const now = new Date()
    const expected = new Date(now.getTime() - now.getTimezoneOffset() * 60_000).toISOString().slice(0, 10)

    render(<MigrantRegistryForm draftsEnabled onSaveDraft={vi.fn()} onSubmit={vi.fn()} />)

    expect(await screen.findByLabelText('Fecha de atención')).toHaveValue(expected)
  })
})
