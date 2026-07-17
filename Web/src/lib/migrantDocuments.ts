import { apiFetch, buildApiUrl } from './api'
import { getCsrfToken } from './csrf'
import type { MigrantDocument } from '../types/migrantDocuments'

export type MigrantDocumentListResponse = {
  data: MigrantDocument[]
}

export type MigrantDocumentStoreResponse = {
  data: MigrantDocument
}

export type MigrantDocumentDeleteResponse = {
  message: string
}

export async function listMigrantDocuments(entryId: number) {
  return apiFetch<MigrantDocumentListResponse>(
    `/registry/migrants/${entryId}/documents`,
  )
}

export async function uploadMigrantDocument(
  entryId: number,
  payload: { file: File, label?: string },
) {
  const { csrfToken } = await getCsrfToken()
  const formData = new FormData()

  formData.set('file', payload.file)

  if (payload.label?.trim()) {
    formData.set('label', payload.label.trim())
  }

  return apiFetch<MigrantDocumentStoreResponse>(
    `/registry/migrants/${entryId}/documents`,
    {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: formData,
    },
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

export function getMigrantDocumentDownloadUrl(entryId: number, documentId: number) {
  return buildApiUrl(`/registry/migrants/${entryId}/documents/${documentId}/download`)
}

export type { MigrantDocument } from '../types/migrantDocuments'
