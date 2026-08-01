import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiRequestError } from '../../lib/api'
import {
  getDocumentSignaturePolicySignerOptions,
  updateDocumentRevisionSignaturePolicy,
  type DocumentDetailRevision,
} from '../../lib/documents'
import { DocumentSignaturePolicyPanel } from './DocumentSignaturePolicyPanel'

vi.mock('../../lib/documents', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../lib/documents')>()
  return {
    ...actual,
    getDocumentSignaturePolicySignerOptions: vi.fn(),
    updateDocumentRevisionSignaturePolicy: vi.fn(),
  }
})

const mockedOptions = vi.mocked(getDocumentSignaturePolicySignerOptions)
const mockedUpdate = vi.mocked(updateDocumentRevisionSignaturePolicy)

const revision = (
  canManageSignaturePolicy: boolean,
  requirements: DocumentDetailRevision['signaturePolicy']['requirements'] = [],
): DocumentDetailRevision => ({
  capabilities: {
    canDownload: true,
    canManageSignaturePolicy,
    canReadVerificationBundle: true,
    canSign: true,
  },
  createdAt: '2026-07-24T12:00:00Z',
  createdBy: { id: 1, name: 'Admin' },
  id: 5,
  mimeType: 'application/pdf',
  originalFileName: 'policy.pdf',
  parentRevisionId: null,
  revisionNumber: 1,
  sha256: 'a'.repeat(64),
  signaturePolicy: {
    requirements,
    signatureOrderEnforced: false,
    version: 1,
  },
  signatureStatus: 'unsigned',
  signatures: [],
  sizeBytes: 100,
})

describe('DocumentSignaturePolicyPanel', () => {
  beforeEach(() => {
    mockedOptions.mockResolvedValue({
      message: 'Loaded.',
      roles: [
        { label: 'Administrator', value: 'admin' },
        { label: 'Coordinator', value: 'coordinator' },
      ],
      users: [
        {
          email: 'coordinator@example.test',
          id: 2,
          name: 'Case Coordinator',
          role: 'coordinator',
        },
      ],
    })
    mockedUpdate.mockResolvedValue({
      message: 'Saved.',
      revision: {
        id: 5,
        signaturePolicy: {
          requirements: [],
          signatureOrderEnforced: false,
          version: 2,
        },
        signatureStatus: 'unsigned',
      },
    })
  })

  it('allows an admin to add and save a revision policy', async () => {
    const onSaved = vi.fn().mockResolvedValue(undefined)
    render(
      <DocumentSignaturePolicyPanel
        documentId={9}
        onReload={vi.fn()}
        onSaved={onSaved}
        revision={revision(true)}
      />,
    )

    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Agregar requisito de firma',
      }),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Guardar política' }))

    await waitFor(() =>
      expect(mockedUpdate).toHaveBeenCalledWith(9, 5, {
        expectedVersion: 1,
        requirements: [{ id: undefined, role: 'coordinator', type: 'role' }],
        signatureOrderEnforced: false,
      }),
    )
    expect(onSaved).toHaveBeenCalled()
  })

  it('renders fulfilled requirements as immutable progress', () => {
    render(
      <DocumentSignaturePolicyPanel
        documentId={9}
        onReload={vi.fn()}
        onSaved={vi.fn().mockResolvedValue(undefined)}
        revision={revision(false, [
          {
            fulfilledAt: '2026-07-24T13:00:00Z',
            fulfilledBy: {
              id: 2,
              name: 'Case Coordinator',
              role: 'coordinator',
            },
            fulfilledBySignatureId: 12,
            id: 3,
            sequence: 1,
            signerRole: 'coordinator',
            signerUser: null,
            type: 'role',
          },
        ])}
      />,
    )

    expect(screen.getByText('Case Coordinator')).toBeInTheDocument()
    expect(screen.getByText(/Completado/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Guardar política' })).not.toBeInTheDocument()
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  })

  it('retains edits and offers reload after a stale save', async () => {
    const onReload = vi.fn()
    mockedUpdate.mockRejectedValueOnce(
      new ApiRequestError('The signature policy changed.', 409),
    )

    render(
      <DocumentSignaturePolicyPanel
        documentId={9}
        onReload={onReload}
        onSaved={vi.fn().mockResolvedValue(undefined)}
        revision={revision(true)}
      />,
    )

    fireEvent.click(
      await screen.findByRole('button', {
        name: 'Agregar requisito de firma',
      }),
    )
    fireEvent.click(screen.getByRole('button', { name: 'Guardar política' }))

    expect(
      await screen.findByText('The signature policy changed.'),
    ).toBeInTheDocument()
    expect(screen.getByRole('combobox', { name: 'Rol del requisito 1' })).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Recargar política' }))
    expect(onReload).toHaveBeenCalled()
  })
})
