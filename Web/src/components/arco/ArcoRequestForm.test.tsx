import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi } from 'vitest'

import type { AuthenticatedUser } from '../../lib/auth'
import type { RegistryEntry } from '../../lib/registry'
import { ArcoRequestForm } from './ArcoRequestForm'

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
})
