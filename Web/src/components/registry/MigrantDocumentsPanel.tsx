import { useEffect, useState } from 'react'

import { ApiRequestError } from '../../lib/api'
import {
  deleteMigrantDocument,
  listMigrantDocuments,
  startMigrantDocumentDownload,
  verifyMigrantDocumentDownload,
} from '../../lib/migrantDocuments'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion } from '../../lib/webauthn'
import type { MigrantDocument } from '../../types/migrantDocuments'
import { AppIcon } from '../ui/AppIcon'

type MigrantDocumentsPanelProps = {
  /** Whether the current session may remove documents (pre-approval only). */
  canDelete: boolean
  /** Whether the current session may list document metadata. */
  canView: boolean
  /** Whether the current session may passkey-authenticate a document download. */
  canDownload?: boolean
  entryId: number
  onSessionExpired?: () => void
  embedded?: boolean
}

const formatBytes = (bytes: number): string => {
  if (bytes < 1024) {
    return `${bytes} B`
  }

  const units = ['KB', 'MB', 'GB']
  let size = bytes / 1024
  let unitIndex = 0

  while (size >= 1024 && unitIndex < units.length - 1) {
    size /= 1024
    unitIndex += 1
  }

  return `${size.toFixed(1)} ${units[unitIndex]}`
}

export function MigrantDocumentsPanel({
  canDelete,
  canDownload = false,
  canView,
  entryId,
  onSessionExpired,
  embedded = false,
}: MigrantDocumentsPanelProps) {
  const [documents, setDocuments] = useState<MigrantDocument[]>([])
  const [isLoading, setIsLoading] = useState(canView)
  const [error, setError] = useState<string | null>(null)
  const [feedback, setFeedback] = useState<string | null>(null)
  const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null)
  const [pendingDownloadId, setPendingDownloadId] = useState<number | null>(null)
  const [downloadConfirmation, setDownloadConfirmation] = useState<MigrantDocument | null>(null)

  const handleSessionError = (caught: unknown): boolean => {
    if (caught instanceof ApiRequestError && caught.status === 401) {
      onSessionExpired?.()
      return true
    }

    return false
  }

  useEffect(() => {
    if (!canView) {
      setIsLoading(false)
      return
    }

    let isMounted = true
    setIsLoading(true)
    setDownloadConfirmation(null)

    listMigrantDocuments(entryId)
      .then((response) => {
        if (isMounted) {
          setDocuments(response.data)
          setError(null)
        }
      })
      .catch((caught: unknown) => {
        if (!isMounted || handleSessionError(caught)) {
          return
        }

        setError(caught instanceof Error ? caught.message : 'Unable to load documents.')
      })
      .finally(() => {
        if (isMounted) {
          setIsLoading(false)
        }
      })

    return () => {
      isMounted = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canView, entryId])

  const handleDelete = async (documentId: number) => {
    setFeedback(null)
    setError(null)
    setPendingDeleteId(documentId)

    try {
      await deleteMigrantDocument(entryId, documentId)
      setDocuments((current) => current.filter((document) => document.id !== documentId))
      setFeedback('Document removed from the registration.')
    } catch (caught: unknown) {
      if (handleSessionError(caught)) {
        return
      }

      setError(caught instanceof Error ? caught.message : 'Unable to remove the document.')
    } finally {
      setPendingDeleteId(null)
    }
  }

  const handleDownload = async (document: MigrantDocument) => {
    setFeedback(null)
    setError(null)
    setPendingDownloadId(document.id)
    let challengeIntentId: string | null = null

    try {
      const options = await startMigrantDocumentDownload(entryId, document.id)
      challengeIntentId = options.challengeIntent.id
      const assertion = await getWebauthnAssertion(options.options)
      const blob = await verifyMigrantDocumentDownload(entryId, document.id, assertion)
      const url = URL.createObjectURL(blob)
      const link = window.document.createElement('a')
      link.href = url
      link.download = document.original_file_name
      link.click()
      URL.revokeObjectURL(url)
      setFeedback(`"${document.original_file_name}" was downloaded.`)
    } catch (caught: unknown) {
      if (challengeIntentId && caught instanceof DOMException && caught.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId).catch(() => undefined)
      }

      if (handleSessionError(caught)) {
        return
      }

      setError(
        caught instanceof Error && caught.name === 'NotAllowedError'
          ? 'Passkey download was cancelled.'
          : caught instanceof Error
            ? caught.message
            : 'Unable to download the document.',
      )
    } finally {
      setPendingDownloadId(null)
    }
  }

  const requestDownload = (document: MigrantDocument) => {
    if (!document.arco_access_completed) {
      setDownloadConfirmation(document)
      return
    }

    void handleDownload(document)
  }

  return (
    <section className={embedded ? 'migrant-documents migrant-documents--embedded' : 'workspace-panel'}>
      {!embedded ? <h2 className="workspace-panel__title">Supporting documents</h2> : null}
      {!embedded ? (
        <p className="workspace-panel__copy">
          Files stay linked to this registration and are purged if the record is cancelled through
          an ARCO request.
        </p>
      ) : null}

      {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
      {feedback ? <div className="login-feedback login-feedback--success">{feedback}</div> : null}

      {canView ? (
        isLoading ? (
          <p className="workspace-panel__copy">Loading documents...</p>
        ) : documents.length === 0 ? (
          <p className="workspace-panel__copy">No documents attached yet.</p>
        ) : (
          <ul className="migrant-documents__list">
            {documents.map((doc) => (
              <li className="migrant-documents__item" key={doc.id}>
                <div>
                  <strong>{doc.label ? `${doc.label} — ` : ''}{doc.original_file_name}</strong>
                  <div className="migrant-documents__meta">
                    {formatBytes(doc.size_bytes)} · {doc.mime_type ?? 'unknown type'} ·{' '}
                    {doc.uploaded_by_role}
                  </div>
                </div>
                <div className="migrant-documents__actions">
                  {canDownload ? (
                    <button
                      className="session-action session-action--quiet"
                      disabled={pendingDownloadId === doc.id}
                      onClick={() => requestDownload(doc)}
                      type="button"
                    >
                      <AppIcon name="download" />
                      {pendingDownloadId === doc.id ? 'Authenticating...' : 'Download'}
                    </button>
                  ) : null}
                  {canDelete ? (
                    <button
                      className="session-action session-action--quiet"
                      disabled={pendingDeleteId === doc.id}
                      onClick={() => handleDelete(doc.id)}
                      type="button"
                    >
                      {pendingDeleteId === doc.id ? 'Removing...' : 'Remove'}
                    </button>
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        )
      ) : null}

      {downloadConfirmation ? (
        <div
          aria-labelledby={`migrant-document-download-confirmation-title-${entryId}`}
          aria-modal="true"
          className="confirmation-modal"
          onClick={(event) => {
            if (event.target === event.currentTarget) {
              setDownloadConfirmation(null)
            }
          }}
          onKeyDown={(event) => {
            if (event.key === 'Escape') {
              setDownloadConfirmation(null)
            }
          }}
          role="dialog"
        >
          <div className="confirmation-modal__surface">
            <div>
              <h3 id={`migrant-document-download-confirmation-title-${entryId}`}>Document outside completed ARCO Access</h3>
              <p>
                <strong>{downloadConfirmation.original_file_name}</strong> has not been covered by a
                completed ARCO Access request. Continue only when downloading it is authorized for
                the current case. The download will require your passkey and will be audited.
              </p>
            </div>
            <div className="confirmation-modal__actions">
              <button
                autoFocus
                className="session-action session-action--quiet"
                onClick={() => setDownloadConfirmation(null)}
                type="button"
              >
                Cancel
              </button>
              <button
                className="session-action"
                onClick={() => {
                  const document = downloadConfirmation
                  setDownloadConfirmation(null)
                  void handleDownload(document)
                }}
                type="button"
              >
                <AppIcon name="download" />
                Continue to passkey
              </button>
            </div>
          </div>
        </div>
      ) : null}
    </section>
  )
}
