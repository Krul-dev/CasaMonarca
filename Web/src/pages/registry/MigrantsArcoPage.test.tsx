import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import * as arco from '../../lib/arco'
import type { AuthenticatedUser } from '../../lib/auth'
import * as registry from '../../lib/registry'
import * as webauthn from '../../lib/webauthn'
import type { ArcoRequest } from '../../types/arco'
import { MigrantsArcoPage } from './MigrantsArcoPage'

vi.mock('../../lib/arco', () => ({
  getArcoAccessDocumentUrl: vi.fn(),
  getArcoRequests: vi.fn(),
  startArcoDecision: vi.fn(),
  verifyArcoDecision: vi.fn(),
}))

vi.mock('../../lib/registry', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/registry')>()
  return { ...actual, getRegistryEntries: vi.fn() }
})

vi.mock('../../lib/webauthn', () => ({ getWebauthnAssertion: vi.fn() }))
vi.mock('../../components/arco/ArcoRequestForm', () => ({ ArcoRequestForm: () => <div>ARCO request form</div> }))
vi.mock('../../components/registry/MigrantQuestionnaireViewer', () => ({ MigrantQuestionnaireViewer: () => null }))
vi.mock('../../components/registry/MigrantDocumentsPanel', () => ({ MigrantDocumentsPanel: () => null }))

const user: AuthenticatedUser = {
  capabilities: {
    modules: { admin: false, dashboard: true, documents: true, history: true, invites: false, logging: true, upload: true },
    security: {
      enrolled: { passkey: true, totp: true }, enforced: true, isFullyEnrolled: true,
      missing: { passkey: false, totp: false }, requires: { passkey: true, totp: true },
    },
  },
  email: 'coordinator@casamonarca.local', id: 2, name: 'Coordinator', role: 'coordinator',
}

const request: ArcoRequest = {
  created_at: '2026-07-21T12:00:00Z',
  escalated_to_admin: false,
  id: 17,
  original_payload_json: { fullName: 'Maria Doe' },
  reason: 'Correct personal information',
  registry_entry_id: 42,
  request_type: 'rectification',
  requested_by: 3,
  requested_by_role: 'non_coordinator',
  status: 'pending_coordinator',
  updated_at: '2026-07-21T12:00:00Z',
}

describe('MigrantsArcoPage', () => {
  beforeEach(() => {
    vi.mocked(registry.getRegistryEntries).mockResolvedValue({ data: [] })
    vi.mocked(arco.getArcoRequests).mockResolvedValue({ data: [request] })
    vi.mocked(arco.startArcoDecision).mockReset()
    vi.mocked(arco.verifyArcoDecision).mockReset()
    vi.mocked(webauthn.getWebauthnAssertion).mockReset()
  })

  it('requires an in-app reason before authenticating an ARCO rejection', async () => {
    const browserUser = userEvent.setup()
    const assertion = {
      id: 'credential-id', rawId: 'raw-id', type: 'public-key' as const,
      response: { authenticatorData: 'authenticator-data', clientDataJSON: 'client-data', signature: 'signature' },
    }
    vi.mocked(arco.startArcoDecision).mockResolvedValue({
      challengeIntent: { expiresAt: '2026-07-21T13:05:00Z', id: 'challenge-id', purpose: 'migrant.arco.coordinator-decision', status: 'pending' },
      message: 'Challenge created.', options: { challenge: 'challenge', rpId: 'localhost' },
    })
    vi.mocked(webauthn.getWebauthnAssertion).mockResolvedValue(assertion)
    vi.mocked(arco.verifyArcoDecision).mockResolvedValue({ data: { id: 17 }, message: 'ARCO decision signed and completed.' } as never)
    render(<MigrantsArcoPage user={user} />)

    await browserUser.click(await screen.findByRole('button', { name: 'Reject' }))

    const dialog = screen.getByRole('dialog', { name: 'Reject ARCO request' })
    expect(dialog).toBeInTheDocument()
    expect(arco.startArcoDecision).not.toHaveBeenCalled()
    expect(screen.getByRole('button', { name: 'Reject with passkey' })).toBeDisabled()

    await browserUser.type(screen.getByLabelText(/Resolution reason/), 'The proposed correction is not supported.')
    await browserUser.click(screen.getByRole('button', { name: 'Reject with passkey' }))

    await waitFor(() => expect(arco.startArcoDecision).toHaveBeenCalledWith(17, 'coordinator', {
      decision: 'reject',
      reason: 'The proposed correction is not supported.',
    }))
    expect(webauthn.getWebauthnAssertion).toHaveBeenCalledTimes(1)
    expect(arco.verifyArcoDecision).toHaveBeenCalledWith(17, 'coordinator', assertion)
  })
})
