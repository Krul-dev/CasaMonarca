import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import type { AuthenticatedUser } from '../../lib/auth'
import * as migrantDocuments from '../../lib/migrantDocuments'
import * as registry from '../../lib/registry'
import * as webauthn from '../../lib/webauthn'
import { MigrantRegistrationsPage } from './MigrantRegistrationsPage'

vi.mock('../../lib/migrantDocuments', async () => {
  const actual = await vi.importActual<typeof import('../../lib/migrantDocuments')>(
    '../../lib/migrantDocuments',
  )

  return {
    ...actual,
    listMigrantDocuments: vi.fn(),
    startMigrantDocumentDownload: vi.fn(),
    verifyMigrantDocumentDownload: vi.fn(),
  }
})

vi.mock('../../lib/registry', async () => {
  const actual = await vi.importActual<typeof import('../../lib/registry')>(
    '../../lib/registry',
  )

  return { ...actual, getRegistryEntries: vi.fn() }
})

vi.mock('../../lib/webauthn', () => ({ getWebauthnAssertion: vi.fn() }))

const getRegistryEntriesMock = vi.mocked(registry.getRegistryEntries)
const listMigrantDocumentsMock = vi.mocked(migrantDocuments.listMigrantDocuments)
const startMigrantDocumentDownloadMock = vi.mocked(migrantDocuments.startMigrantDocumentDownload)
const verifyMigrantDocumentDownloadMock = vi.mocked(migrantDocuments.verifyMigrantDocumentDownload)
const getWebauthnAssertionMock = vi.mocked(webauthn.getWebauthnAssertion)

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
      enrolled: { passkey: false, totp: true },
      enforced: false,
      isFullyEnrolled: true,
      missing: { passkey: false, totp: false },
      requires: { passkey: false, totp: true },
    },
  },
  email: 'reviewer@casamonarca.local',
  id: 7,
  name: 'Registry Reviewer',
  role: 'non_coordinator',
}

describe('MigrantRegistrationsPage', () => {
  beforeEach(() => {
    window.sessionStorage.clear()
    getRegistryEntriesMock.mockReset()
    listMigrantDocumentsMock.mockReset()
    startMigrantDocumentDownloadMock.mockReset()
    verifyMigrantDocumentDownloadMock.mockReset()
    getWebauthnAssertionMock.mockReset()
    getRegistryEntriesMock.mockResolvedValue({
      data: [{
        created_at: '2026-07-17T12:00:00Z',
        created_by: 3,
        created_by_role: 'volunteer',
        current_status: 'approved',
        id: 42,
        payload_json: {
          attentionDate: '2026-07-17',
          countryOfOrigin: 'Honduras',
          fullName: 'Maria Doe',
          populationGroup: 'adult',
        },
        updated_at: '2026-07-17T13:00:00Z',
      }],
    })
    listMigrantDocumentsMock.mockResolvedValue({
      data: [{
        arco_access_completed: false,
        created_at: '2026-07-17T12:00:00Z',
        id: 9,
        label: 'Identification',
        mime_type: 'application/pdf',
        original_file_name: 'passport.pdf',
        registry_entry_id: 42,
        sha256: 'abc123',
        size_bytes: 2048,
        updated_at: '2026-07-17T12:00:00Z',
        uploaded_by_role: 'volunteer',
      }],
    })
  })

  it('loads document metadata without exposing downloads to a non-coordinator', async () => {
    const browserUser = userEvent.setup()

    render(<MigrantRegistrationsPage user={user} />)

    expect(await screen.findByText('Maria Doe')).toBeInTheDocument()
    expect(listMigrantDocumentsMock).not.toHaveBeenCalled()

    await browserUser.click(screen.getByText('View registration details'))

    await waitFor(() => expect(listMigrantDocumentsMock).toHaveBeenCalledWith(42))
    expect(await screen.findByText('Identification — passport.pdf')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Download' })).not.toBeInTheDocument()
  })

  it('searches registration text without requiring accents', async () => {
    const browserUser = userEvent.setup()
    getRegistryEntriesMock.mockResolvedValueOnce({
      data: [{
        created_at: '2026-07-17T12:00:00Z', created_by: 3, created_by_role: 'volunteer',
        current_status: 'approved', id: 42,
        payload_json: { countryOfOrigin: 'México', fullName: 'María López', populationGroup: 'adult' },
        updated_at: '2026-07-17T13:00:00Z',
      }],
    })
    render(<MigrantRegistrationsPage user={user} />)

    await browserUser.type(await screen.findByLabelText('Search'), 'maria lopez mexico')
    await waitFor(() => expect(JSON.parse(window.sessionStorage.getItem('casa-monarca.migrant-registrations.filters') ?? '{}').search).toBe('maria lopez mexico'))

    expect(screen.getByText('María López')).toBeInTheDocument()
  })

  it('passkey-authenticates coordinator document downloads', async () => {
    const browserUser = userEvent.setup()
    const coordinator = { ...user, role: 'coordinator' as const }
    const assertion = {
      id: 'credential-coordinator',
      rawId: 'credential-coordinator',
      type: 'public-key' as const,
      response: {
        authenticatorData: 'authenticator-data',
        clientDataJSON: 'client-data',
        signature: 'signature',
      },
    }
    startMigrantDocumentDownloadMock.mockResolvedValue({
      challengeIntent: {
        expiresAt: '2026-07-17T13:01:00Z',
        id: 'challenge-id',
        purpose: 'migrant.registry.document.download',
        status: 'pending',
      },
      message: 'Challenge created.',
      options: {
        challenge: 'challenge',
        rpId: 'localhost',
      },
    })
    getWebauthnAssertionMock.mockResolvedValue(assertion)
    verifyMigrantDocumentDownloadMock.mockResolvedValue(new Blob(['private document']))
    const createObjectUrl = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:download')
    const revokeObjectUrl = vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const anchorClick = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    render(<MigrantRegistrationsPage user={coordinator} />)
    await browserUser.click(await screen.findByText('View registration details'))
    await browserUser.click(await screen.findByRole('button', { name: 'Download' }))

    expect(screen.getByRole('dialog', { name: 'Document outside completed ARCO Access' })).toBeInTheDocument()
    expect(startMigrantDocumentDownloadMock).not.toHaveBeenCalled()

    await browserUser.click(screen.getByRole('button', { name: 'Continue to passkey' }))

    await waitFor(() => expect(verifyMigrantDocumentDownloadMock).toHaveBeenCalledWith(42, 9, assertion))
    expect(startMigrantDocumentDownloadMock).toHaveBeenCalledWith(42, 9)
    expect(getWebauthnAssertionMock).toHaveBeenCalledTimes(1)
    expect(anchorClick).toHaveBeenCalledTimes(1)
    expect(createObjectUrl).toHaveBeenCalledTimes(1)
    expect(revokeObjectUrl).toHaveBeenCalledWith('blob:download')
  })
})
