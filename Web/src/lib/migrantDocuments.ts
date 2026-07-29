import { ApiRequestError, apiFetch, buildApiUrl } from './api'
import type { WebauthnLoginAssertionPayload, WebauthnLoginOptions } from './auth'
import { getCsrfToken } from './csrf'
import type { SecurityChallengeSummary } from './securityChallenges'
import type { MigrantDocument } from '../types/migrantDocuments'

export type MigrantDocumentListResponse = {
  data: MigrantDocument[]
}

export type MigrantDocumentDeleteResponse = {
  message: string
}

export type PendingMigrantDocument = {
  file: File
  label: string
}

export type MigrantDocumentDownloadOptionsResponse = {
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}

export async function listMigrantDocuments(entryId: number) {
  return apiFetch<MigrantDocumentListResponse>(
    `/registry/migrants/${entryId}/documents`,
  )
}

export async function deleteMigrantDocument(entryId: number, documentId: number) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<MigrantDocumentDeleteResponse>(
    `/registry/migrants/${entryId}/documents/${documentId}`,
    {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    },
  )
}

export async function startMigrantDocumentDownload(entryId: number, documentId: number) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<MigrantDocumentDownloadOptionsResponse>(
    `/registry/migrants/${entryId}/documents/${documentId}/download/options`,
    {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken },
    },
  )
}

export async function verifyMigrantDocumentDownload(
  entryId: number,
  documentId: number,
  assertion: WebauthnLoginAssertionPayload,
) {
  const { csrfToken } = await getCsrfToken()
  const response = await fetch(
    buildApiUrl(`/registry/migrants/${entryId}/documents/${documentId}/download/verify`),
    {
      method: 'POST',
      credentials: 'include',
      headers: {
        Accept: 'application/octet-stream',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(assertion),
    },
  )

  if (!response.ok) {
    const payload = await response.json().catch(() => null) as { message?: unknown } | null
    const message = typeof payload?.message === 'string'
      ? payload.message
      : `Request failed with status ${response.status}`

    throw new ApiRequestError(message, response.status)
  }

  return response.blob()
}

export type { MigrantDocument } from '../types/migrantDocuments'
