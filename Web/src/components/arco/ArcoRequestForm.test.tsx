import { act, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as arco from '../../lib/arco'
import type { AuthenticatedUser } from '../../lib/auth'
import type { RegistryEntry } from '../../lib/registry'
import * as webauthn from '../../lib/webauthn'
import { ArcoRequestForm } from './ArcoRequestForm'

vi.mock('../../lib/arco', () => ({
  startArcoRequest: vi.fn(),
  verifyArcoRequest: vi.fn(),
}))

vi.mock('../../lib/webauthn', () => ({
  getWebauthnAssertion: vi.fn(),
  isIpHostname: vi.fn(() => false),
}))

let submitRectification: (() => Promise<void>) | null = null

vi.mock('../registry/MigrantRegistryForm', () => ({
  MigrantRegistryForm: ({ onSubmit }: { onSubmit: (payload: { fullName: string }) => Promise<void> }) => {
    submitRectification = () => onSubmit({ fullName: 'Maria Corrected' })
    return <div>Rectification questionnaire</div>
  },
}))

vi.mock('../registry/MigrantDocumentsPanel', () => ({
  MigrantDocumentsPanel: ({ entryId }: { entryId: number }) => (
    <div>Covered documents for registration {entryId}</div>
  ),
}))

const user: AuthenticatedUser = {
  capabilities: {
    modules: {
      admin: false,
      dashboard: true,
      documents: true,
      history: true,
      invites: false,
      logging: true,
      upload: true,
    },
    security: {
      enrolled: { passkey: true, totp: true },
      enforced: true,
      isFullyEnrolled: true,
      missing: { passkey: false, totp: false },
      requires: { passkey: true, totp: true },
    },
  },
  email: 'coordinator@casamonarca.local',
  id: 2,
  name: 'Coordinator',
  role: 'coordinator',
}

const entry: RegistryEntry = {
  created_at: '2026-07-17T12:00:00Z',
  created_by: 4,
  created_by_role: 'volunteer',
  current_status: 'approved',
  id: 42,
  payload_json: {
    attentionDate: '2026-07-17',
    birthDate: '1990-01-01',
    civilStatus: 'single',
    countryOfOrigin: 'Honduras',
    departmentState: 'Cortes',
    firstLastName: 'Doe',
    firstName: 'Maria',
    fullName: 'Maria Doe',
    gender: 'female',
    populationGroup: 'adult',
    secondLastName: '',
  },
  updated_at: '2026-07-17T12:00:00Z',
}

describe('ArcoRequestForm', () => {
  beforeEach(() => {
    vi.mocked(arco.startArcoRequest).mockReset()
    vi.mocked(arco.verifyArcoRequest).mockReset()
    vi.mocked(webauthn.getWebauthnAssertion).mockReset()
    submitRectification = null
    Object.defineProperty(window, 'isSecureContext', { configurable: true, value: true })
    Object.defineProperty(window, 'PublicKeyCredential', { configurable: true, value: class PublicKeyCredential {} })
  })

  it('enables the three supported rights and includes the selected registration documents', async () => {
    const browserUser = userEvent.setup()

    render(<ArcoRequestForm entries={[entry]} onCreated={vi.fn()} user={user} />)

    expect(screen.getByRole('option', { name: 'Access' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Rectification' })).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Cancellation' })).toBeInTheDocument()
    expect(screen.queryByRole('option', { name: 'Opposition' })).not.toBeInTheDocument()

    await browserUser.selectOptions(screen.getByLabelText('Registration'), '42')

    expect(screen.getByText('Covered documents for registration 42')).toBeInTheDocument()
  })

  it('keeps rectification data when authentication setup fails', async () => {
    const browserUser = userEvent.setup()
    vi.mocked(arco.startArcoRequest).mockRejectedValue(new Error('Authentication options failed.'))
    render(<ArcoRequestForm entries={[entry]} onCreated={vi.fn()} user={user} />)

    await browserUser.selectOptions(screen.getByLabelText('Registration'), '42')
    await browserUser.selectOptions(screen.getByLabelText('Right'), 'rectification')
    await browserUser.type(screen.getByLabelText('Reason'), 'Correct the registered surname')

    expect(submitRectification).not.toBeNull()
    await act(async () => {
      await expect(submitRectification?.()).rejects.toThrow('Authentication options failed.')
    })
    expect(screen.getByLabelText('Registration')).toHaveValue('42')
    expect(screen.getByLabelText('Reason')).toHaveValue('Correct the registered surname')
  })

  it('requests and verifies a passkey before completing a rectification', async () => {
    const browserUser = userEvent.setup()
    const onCreated = vi.fn().mockResolvedValue(undefined)
    const assertion = {
      id: 'credential-id', rawId: 'raw-id', type: 'public-key' as const,
      response: { authenticatorData: 'authenticator-data', clientDataJSON: 'client-data', signature: 'signature' },
    }
    vi.mocked(arco.startArcoRequest).mockResolvedValue({
      challengeIntent: { expiresAt: '2026-07-21T13:00:00Z', id: 'challenge-id', purpose: 'migrant.arco.create', status: 'pending' },
      message: 'Challenge created.',
      options: { challenge: 'challenge', rpId: 'localhost' },
    })
    vi.mocked(webauthn.getWebauthnAssertion).mockResolvedValue(assertion)
    vi.mocked(arco.verifyArcoRequest).mockResolvedValue({ data: { id: 9 }, message: 'Rectification request submitted.' } as never)
    render(<ArcoRequestForm entries={[entry]} onCreated={onCreated} user={user} />)

    await browserUser.selectOptions(screen.getByLabelText('Registration'), '42')
    await browserUser.selectOptions(screen.getByLabelText('Right'), 'rectification')
    await browserUser.type(screen.getByLabelText('Reason'), 'Correct the registered surname')
    await act(async () => { await submitRectification?.() })

    expect(webauthn.getWebauthnAssertion).toHaveBeenCalledTimes(1)
    expect(arco.verifyArcoRequest).toHaveBeenCalledWith(assertion)
    expect(onCreated).toHaveBeenCalledTimes(1)
  })
})
