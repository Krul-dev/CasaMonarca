import { render, screen, waitFor } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { listMigrantDocuments } from '../../lib/migrantDocuments'
import { MigrantDocumentsPanel } from './MigrantDocumentsPanel'

vi.mock('../../lib/migrantDocuments', () => ({
  deleteMigrantDocument: vi.fn(),
  listMigrantDocuments: vi.fn(),
  startMigrantDocumentDownload: vi.fn(),
  verifyMigrantDocumentDownload: vi.fn(),
}))

vi.mock('../../lib/securityChallenges', () => ({
  cancelSecurityChallenge: vi.fn(),
}))

vi.mock('../../lib/webauthn', () => ({
  getWebauthnAssertion: vi.fn(),
}))

const mockedListMigrantDocuments = vi.mocked(listMigrantDocuments)

describe('MigrantDocumentsPanel', () => {
  beforeEach(() => {
    mockedListMigrantDocuments.mockResolvedValue({
      data: [
        {
          arco_access_completed: true,
          created_at: '2026-07-20T12:00:00Z',
          id: 11,
          mime_type: 'application/pdf',
          original_file_name: 'covered.pdf',
          registry_entry_id: 4,
          sha256: 'a'.repeat(64),
          size_bytes: 1200,
          updated_at: '2026-07-20T12:00:00Z',
          uploaded_by_role: 'volunteer',
        },
        {
          arco_access_completed: false,
          created_at: '2026-07-21T12:00:00Z',
          id: 12,
          mime_type: 'application/pdf',
          original_file_name: 'uncovered.pdf',
          registry_entry_id: 4,
          sha256: 'b'.repeat(64),
          size_bytes: 1300,
          updated_at: '2026-07-21T12:00:00Z',
          uploaded_by_role: 'volunteer',
        },
      ],
    })
  })

  it('offers non-coordinator download only for documents covered by completed ARCO access', async () => {
    render(
      <MigrantDocumentsPanel
        canDelete={false}
        canDownloadArcoApproved
        canView
        embedded
        entryId={4}
      />,
    )

    await waitFor(() => expect(screen.getByText('covered.pdf')).toBeInTheDocument())

    expect(screen.getAllByRole('button', { name: /download/i })).toHaveLength(1)
    expect(screen.getByText('uncovered.pdf')).toBeInTheDocument()
  })
})
