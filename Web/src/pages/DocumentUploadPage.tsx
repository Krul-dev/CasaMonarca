import { useRef, useState } from 'react'

import { AppIcon } from '../components/ui/AppIcon'
import { APP_ADMIN_PATH, APP_DOCUMENTS_PATH } from '../config/appRoutes'
import type { AuthenticatedUser } from '../lib/auth'
import { ApiRequestError } from '../lib/api'
import { uploadDocument, type DocumentSummary } from '../lib/documents'

type DocumentUploadPageProps = {
  onNavigate: (to: string) => void
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type UploadFeedback =
  | {
      document: DocumentSummary
      kind: 'success'
      message: string
    }
  | {
      kind: 'error'
      message: string
    }

const MAX_DOCUMENT_UPLOAD_BYTES = 16 * 1024 * 1024
const MAX_DOCUMENT_UPLOAD_LABEL = '16 MB'

export function DocumentUploadPage({
  onNavigate,
  onSessionExpired,
  user,
}: DocumentUploadPageProps) {
  const [title, setTitle] = useState('')
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [fileInputKey, setFileInputKey] = useState(0)
  const [isDragActive, setIsDragActive] = useState(false)
  const [fieldErrors, setFieldErrors] = useState<{
    file?: string
    title?: string
  }>({})
  const [feedback, setFeedback] = useState<UploadFeedback | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const dragDepthRef = useRef(0)

  const selectFile = (file: File | null) => {
    if (file && file.size > MAX_DOCUMENT_UPLOAD_BYTES) {
      setSelectedFile(null)
      setFieldErrors((current) => ({
        ...current,
        file: `Choose a file smaller than ${MAX_DOCUMENT_UPLOAD_LABEL}.`,
      }))
      setFeedback({
        kind: 'error',
        message: `This document is too large. The upload limit is ${MAX_DOCUMENT_UPLOAD_LABEL}.`,
      })
      return
    }

    setSelectedFile(file)
    setFieldErrors((current) => ({
      ...current,
      file: undefined,
    }))
    setFeedback(null)
  }

  const hasDraggedFiles = (event: React.DragEvent<HTMLElement>) =>
    Array.from(event.dataTransfer.types).includes('Files')

  const resetDragState = () => {
    dragDepthRef.current = 0
    setIsDragActive(false)
  }

  const handleDragEnter = (event: React.DragEvent<HTMLElement>) => {
    if (!hasDraggedFiles(event)) {
      return
    }

    event.preventDefault()
    dragDepthRef.current += 1
    setIsDragActive(true)
  }

  const handleDragOver = (event: React.DragEvent<HTMLElement>) => {
    if (!hasDraggedFiles(event)) {
      return
    }

    event.preventDefault()
    event.dataTransfer.dropEffect = 'copy'
    setIsDragActive(true)
  }

  const handleDragLeave = (event: React.DragEvent<HTMLElement>) => {
    if (!hasDraggedFiles(event)) {
      return
    }

    event.preventDefault()
    dragDepthRef.current = Math.max(0, dragDepthRef.current - 1)

    if (dragDepthRef.current === 0) {
      setIsDragActive(false)
    }
  }

  const handleDrop = (event: React.DragEvent<HTMLElement>) => {
    if (!hasDraggedFiles(event)) {
      return
    }

    event.preventDefault()
    resetDragState()

    const file = event.dataTransfer.files?.[0] ?? null
    selectFile(file)
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const nextFieldErrors: { file?: string; title?: string } = {}

    if (!selectedFile) {
      nextFieldErrors.file = 'Choose a document file before submitting.'
    } else if (selectedFile.size > MAX_DOCUMENT_UPLOAD_BYTES) {
      nextFieldErrors.file = `Choose a file smaller than ${MAX_DOCUMENT_UPLOAD_LABEL}.`
    }

    setFieldErrors(nextFieldErrors)
    setFeedback(null)

    if (Object.keys(nextFieldErrors).length > 0 || !selectedFile) {
      return
    }

    setIsSubmitting(true)

    try {
      const response = await uploadDocument({
        file: selectedFile,
        title,
      })

      setTitle('')
      setSelectedFile(null)
      setFileInputKey((current) => current + 1)
      resetDragState()
      setFeedback({
        kind: 'success',
        message: response.message,
        document: response.document,
      })
      setFieldErrors({})
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setFieldErrors({
        title:
          error instanceof ApiRequestError ? error.errors?.title?.[0] : undefined,
        file:
          error instanceof ApiRequestError ? error.errors?.file?.[0] : undefined,
      })
      setFeedback({
        kind: 'error',
        message:
          error instanceof ApiRequestError && error.status === 413
            ? `This document is too large for the server upload limit. Use a file smaller than ${MAX_DOCUMENT_UPLOAD_LABEL}.`
            : error instanceof Error
              ? error.message
              : 'The document could not be uploaded.',
      })
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">Upload a confidential document</h2>
        <p className="workspace-panel__copy">
          Phase 3 stores the uploaded file on the private Laravel disk and
          records the first revision, owner, uploader, and file hash in MySQL.
        </p>

        <form className="login-form" onSubmit={handleSubmit}>
          <div className="login-form__fields">
            <label className="login-field">
              <span className="login-field__label">Document title</span>
              <input
                className="login-field__input"
                name="title"
                onChange={(event) => setTitle(event.target.value)}
                placeholder="Optional. Defaults to the file name."
                type="text"
                value={title}
              />
              {fieldErrors.title ? (
                <span className="login-field__error">{fieldErrors.title}</span>
              ) : null}
            </label>

            <label
              className={`login-field upload-dropzone${
                isDragActive ? ' upload-dropzone--active' : ''
              }${fieldErrors.file ? ' upload-dropzone--error' : ''}`}
              onDragEnter={handleDragEnter}
              onDragLeave={handleDragLeave}
              onDragOver={handleDragOver}
              onDrop={handleDrop}
            >
              <span className="login-field__label">File</span>
              <input
                key={fileInputKey}
                className="login-field__input upload-dropzone__input"
                name="file"
                onChange={(event) => selectFile(event.target.files?.[0] ?? null)}
                type="file"
              />
              <span className="upload-dropzone__surface">
                <strong className="upload-dropzone__headline">
                  {isDragActive ? 'Release to attach the document' : 'Drag and drop a file here'}
                </strong>
                <span className="upload-dropzone__copy">
                  Or click to browse the local file system.
                </span>
              </span>
              {selectedFile ? (
                <span className="upload-form__hint">
                  Selected file: {selectedFile.name}
                </span>
              ) : (
                <span className="upload-form__hint">
                  Accepted up to {MAX_DOCUMENT_UPLOAD_LABEL} as a confidential intake artifact.
                </span>
              )}
              {fieldErrors.file ? (
                <span className="login-field__error">{fieldErrors.file}</span>
              ) : null}
            </label>
          </div>

          <button className="login-submit" disabled={isSubmitting} type="submit">
            <AppIcon name="upload" />
            {isSubmitting ? 'Uploading document...' : 'Upload document'}
          </button>
        </form>

        {feedback ? (
          <div
            className={`login-feedback ${
              feedback.kind === 'success'
                ? 'login-feedback--success'
                : 'login-feedback--error'
            }`}
          >
            {feedback.message}
          </div>
        ) : null}
      </section>

      <section className="workspace-panel workspace-panel--accent">
        <h2 className="workspace-panel__title">Current intake policy</h2>
        <ul className="route-checklist">
          <li>Every role can upload a document.</li>
          <li>Uploads wait for admin approval before they can be viewed or signed.</li>
          <li>The uploader becomes the initial owner in this first slice.</li>
          <li>Files are stored outside the public web root on the private disk.</li>
        </ul>
      </section>

      {feedback?.kind === 'success' ? (
        <section className="workspace-panel">
          <h2 className="workspace-panel__title">Upload registered</h2>
          <ul className="route-checklist">
            <li>Document: {feedback.document.title}</li>
            <li>Status: Pending admin approval</li>
            <li>
              Current revision:{' '}
              {feedback.document.currentRevision?.revisionNumber ?? 'Not available'}
            </li>
            <li>
              SHA-256:{' '}
              {feedback.document.currentRevision?.sha256 ?? 'Not available'}
            </li>
          </ul>

          {user.capabilities.modules.documents ? (
            <div className="workspace-actions">
              <button
                className="workspace-action workspace-action--secondary"
                onClick={() => onNavigate(APP_DOCUMENTS_PATH)}
                type="button"
              >
                <AppIcon name="document" />
                Open document workspace
              </button>
              {user.role === 'admin' ? (
                <button
                  className="workspace-action workspace-action--primary"
                  onClick={() => onNavigate(`${APP_ADMIN_PATH}?tab=approvals`)}
                  type="button"
                >
                  <AppIcon name="verify" />
                  Review approvals
                </button>
              ) : null}
            </div>
          ) : null}
        </section>
      ) : null}
    </section>
  )
}
