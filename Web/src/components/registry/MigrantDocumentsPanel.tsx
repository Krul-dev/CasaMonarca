import { type FormEvent, useEffect, useRef, useState } from 'react'

import { ApiRequestError } from '../../lib/api'
import {
  deleteMigrantDocument,
  getMigrantDocumentDownloadUrl,
  listMigrantDocuments,
  uploadMigrantDocument,
} from '../../lib/migrantDocuments'
import type { MigrantDocument } from '../../types/migrantDocuments'

const MAX_UPLOAD_BYTES = 16 * 1024 * 1024
const MAX_UPLOAD_LABEL = '16 MB'

type MigrantDocumentsPanelProps = {
  /** Whether the current session may remove documents (pre-approval only). */
  canDelete: boolean
  /** Whether the current session may list and download documents (non-volunteer). */
  canView: boolean
  /** Whether the current session may upload documents. */
  canUpload: boolean
  entryId: number
  maxDocuments?: number
  onSessionExpired?: () => void
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
  canView,
  canUpload,
  entryId,
  maxDocuments = 10,
  onSessionExpired,
}: MigrantDocumentsPanelProps) {
  const [documents, setDocuments] = useState<MigrantDocument[]>([])
  const [isLoading, setIsLoading] = useState(canView)
  const [error, setError] = useState<string | null>(null)
  const [feedback, setFeedback] = useState<string | null>(null)
  const [label, setLabel] = useState('')
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [isUploading, setIsUploading] = useState(false)
  const [pendingDeleteId, setPendingDeleteId] = useState<number | null>(null)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

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

  const reachedLimit = canView && documents.length >= maxDocuments

  const resetFileInput = () => {
    setSelectedFile(null)
    setLabel('')

    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }

  const handleUpload = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setFeedback(null)
    setError(null)

    if (!selectedFile) {
      setError('Choose a file to upload.')
      return
    }

    if (selectedFile.size > MAX_UPLOAD_BYTES) {
      setError(`Files must be ${MAX_UPLOAD_LABEL} or smaller.`)
      return
    }

    setIsUploading(true)

    try {
      const response = await uploadMigrantDocument(entryId, {
        file: selectedFile,
        label,
      })

      if (canView) {
        setDocuments((current) => [response.data, ...current])
      }

      setFeedback(`"${response.data.original_file_name}" was attached to the registration.`)
      resetFileInput()
    } catch (caught: unknown) {
      if (handleSessionError(caught)) {
        return
      }

      setError(caught instanceof Error ? caught.message : 'Unable to upload the document.')
    } finally {
      setIsUploading(false)
    }
  }

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

  return (
    <section className="workspace-panel">
      <h2 className="workspace-panel__title">Supporting documents</h2>
      <p className="workspace-panel__copy">
        Attach the migrant&apos;s supporting files (up to {maxDocuments} per registration,{' '}
        {MAX_UPLOAD_LABEL} each). Files stay linked to this registration and are purged if the
        record is cancelled through an ARCO request.
      </p>

      {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
      {feedback ? <div className="login-feedback login-feedback--success">{feedback}</div> : null}

      {canUpload ? (
        <form className="registry-form migrant-documents__form" onSubmit={handleUpload}>
          <label>
            File
            <input
              ref={fileInputRef}
              disabled={isUploading || reachedLimit}
              onChange={(event) => setSelectedFile(event.target.files?.[0] ?? null)}
              type="file"
            />
          </label>
          <label>
            Label (optional)
            <input
              disabled={isUploading || reachedLimit}
              maxLength={255}
              onChange={(event) => setLabel(event.target.value)}
              placeholder="e.g. Identification, signed privacy notice"
              type="text"
              value={label}
            />
          </label>
          <div className="registry-form__actions">
            <button
              className="session-action"
              disabled={isUploading || reachedLimit || !selectedFile}
              type="submit"
            >
              {isUploading ? 'Uploading...' : 'Attach document'}
            </button>
          </div>
          {reachedLimit ? (
            <p className="workspace-panel__copy">
              This registration reached the maximum of {maxDocuments} documents.
            </p>
          ) : null}
        </form>
      ) : null}

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
                  <a
                    className="session-action session-action--quiet"
                    href={getMigrantDocumentDownloadUrl(entryId, doc.id)}
                    rel="noreferrer"
                    target="_blank"
                  >
                    Download
                  </a>
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
      ) : canUpload ? (
        <p className="workspace-panel__copy">
          Uploaded files are sent for review. Your role cannot list previously attached documents.
        </p>
      ) : null}
    </section>
  )
}
