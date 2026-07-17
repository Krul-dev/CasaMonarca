import { getCsrfToken } from './csrf'
import { apiFetch, ApiRequestError, buildApiUrl } from './api'
import type {
  WebauthnLoginAssertionPayload,
  WebauthnLoginOptions,
  UserRole,
} from './auth'
import type { SecurityChallengeSummary } from './securityChallenges'

export type DocumentActor = {
  id: number | null
  name: string | null
  email?: string | null
  role?: UserRole | null
}

export type DocumentSignatureRequirement = {
  fulfilledAt?: string | null
  fulfilledBySignatureId?: number | null
  id: number
  sequence: number
  signerRole?: UserRole | null
  signerUser?: DocumentActor | null
}

export type DocumentApproval = {
  approvedAt?: string | null
  approvedBy?: DocumentActor | null
  note?: string | null
  signatureOrderEnforced: boolean
  signatureRequirements: DocumentSignatureRequirement[]
}

export type DocumentRevisionSignature = {
  documentHash?: string | null
  expiresAt?: string | null
  id: number
  signatureType: string
  signedAt?: string | null
  signedBy: DocumentActor
  verificationStatus: string
}

export type DocumentCapabilities = {
  canDeleteDocument: boolean
  canDownloadCurrent: boolean
  canReadCurrentVerificationBundle: boolean
  canSignCurrent: boolean
  canUploadRevision: boolean
}

export type DocumentRevisionCapabilities = {
  canDownload: boolean
  canReadVerificationBundle: boolean
  canSign: boolean
}

export type DocumentRevisionSummary = {
  id: number | null
  revisionNumber: number | null
  originalFileName: string | null
  mimeType: string | null
  sizeBytes: number | null
  sha256: string | null
  signatureStatus: string | null
  signatures?: DocumentRevisionSignature[]
}

export type DocumentSummary = {
  approval?: DocumentApproval | null
  capabilities?: DocumentCapabilities
  id: number
  title: string
  status: string
  confidentiality: string
  owner: DocumentActor
  uploadedBy: DocumentActor
  currentRevision: DocumentRevisionSummary | null
  createdAt?: string | null
  updatedAt?: string | null
}

export type DocumentDetailRevision = {
  capabilities: DocumentRevisionCapabilities
  diffMetadata?: Record<string, unknown> | null
  id: number
  parentRevisionId?: number | null
  originalFileName: string
  mimeType: string | null
  revisionNumber: number
  sizeBytes: number
  sha256: string
  signatureStatus: string
  signatures?: DocumentRevisionSignature[]
  createdBy: DocumentActor
  createdAt?: string | null
}

export type DocumentDetail = {
  approval?: DocumentApproval | null
  capabilities: DocumentCapabilities
  id: number
  title: string
  status: string
  confidentiality: string
  owner: DocumentActor
  uploadedBy: DocumentActor
  currentRevision: (DocumentRevisionSummary & {
    createdAt?: string | null
    createdBy: DocumentActor
  }) | null
  revisions: DocumentDetailRevision[]
  createdAt?: string | null
  updatedAt?: string | null
}

export type DocumentVerificationSignature = {
  credential: {
    id?: string | null
    name?: string | null
    publicKey?: string | null
    publicKeyAlgorithm?: number | null
    publicKeyFingerprintSha256?: string | null
    publicKeyFormat?: string | null
    signCount?: number | null
  }
  documentHash?: string | null
  expiresAt?: string | null
  id: number
  signatureType: string
  verificationStatus: string
  signedAt?: string | null
  signedBy: DocumentActor
}

export type DocumentVerification = {
  documentId: number
  currentRevisionId: number | null
  currentRevisionNumber: number | null
  signatureStatus: string
  hasSignatures: boolean
  verified: boolean
  signatures: DocumentVerificationSignature[]
}

export type DocumentVerificationBundleSignature =
  DocumentVerificationSignature & {
    assertion?: {
      id?: string | null
      rawId?: string | null
      response?: {
        authenticatorData?: string | null
        clientDataJSON?: string | null
        signature?: string | null
        userHandle?: string | null
      } | null
      type?: string | null
    } | null
    canonicalIntent?: string | null
    challenge?: string | null
    intent?: {
      documentId?: number | null
      expiresAt?: string | null
      issuedAt?: string | null
      nonce?: string | null
      origin?: string | null
      purpose?: string | null
      revisionId?: number | null
      revisionNumber?: number | null
      revisionSha256?: string | null
      rpId?: string | null
      userId?: number | null
      version?: number | null
    } | null
  }

export type DocumentVerificationBundle = {
  document: {
    id: number
    title: string | null
  }
  revision: {
    id: number | null
    number: number | null
    sha256: string | null
    signatureStatus: string
  }
  signatures: DocumentVerificationBundleSignature[]
  version: number
}

export type DocumentIndexResponse = {
  message: string
  documents: DocumentSummary[]
}

export type DocumentShowResponse = {
  message: string
  document: DocumentDetail
}

export type DocumentStoreResponse = {
  message: string
  document: DocumentSummary
}

export type DocumentVerificationResponse = {
  message: string
  verification: DocumentVerification
}

export type DocumentVerificationBundleResponse = {
  bundle: DocumentVerificationBundle
  message: string
}

export type DocumentSensitiveActionOptionsResponse = {
  challengeIntent?: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
  signingTarget?: {
    documentHash: string
    documentId: number
    expiresAt: string
    revisionId: number
    revisionNumber: number
  }
}

export type DocumentRevisionUpdateOptionsResponse = {
  message: string
  options: WebauthnLoginOptions
  revisionTarget: {
    candidateHash: string
    candidateOriginalFileName: string
    documentId: number
    expiresAt: string
    parentRevisionHash: string
    parentRevisionId: number
    parentRevisionNumber: number
  }
}

export type DocumentSignResponse = {
  message: string
  signature: DocumentVerificationSignature
  verification: DocumentVerification
}

export type DocumentDeleteResponse = {
  message: string
  tombstone: {
    id: number
    originalDocumentId: number
    deletedAt?: string | null
    lastSha256?: string | null
    revisionCount: number
  }
}

export async function uploadDocument(payload: {
  file: File
  title?: string
}): Promise<DocumentStoreResponse> {
  const { csrfToken } = await getCsrfToken()
  const formData = new FormData()

  if (payload.title?.trim()) {
    formData.set('title', payload.title.trim())
  }

  formData.set('file', payload.file)

  return apiFetch<DocumentStoreResponse>('/documents', {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
    body: formData,
  })
}

export async function startDocumentRevisionUpdate(
  documentId: number,
  payload: {
    originalFileName: string
    sha256: string
    sizeBytes: number
  },
): Promise<DocumentRevisionUpdateOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DocumentRevisionUpdateOptionsResponse>(
    `/documents/${documentId}/revisions/options`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    },
  )
}

export async function verifyDocumentRevisionUpdate(
  documentId: number,
  payload: {
    assertion: WebauthnLoginAssertionPayload
    file: File
  },
): Promise<DocumentStoreResponse> {
  const { csrfToken } = await getCsrfToken()
  const formData = new FormData()

  formData.set('file', payload.file)
  formData.set('id', payload.assertion.id)
  formData.set('rawId', payload.assertion.rawId)
  formData.set('type', payload.assertion.type)
  formData.set(
    'response[clientDataJSON]',
    payload.assertion.response.clientDataJSON,
  )
  formData.set(
    'response[authenticatorData]',
    payload.assertion.response.authenticatorData,
  )
  formData.set('response[signature]', payload.assertion.response.signature)

  if (payload.assertion.response.userHandle) {
    formData.set('response[userHandle]', payload.assertion.response.userHandle)
  }

  return apiFetch<DocumentStoreResponse>(
    `/documents/${documentId}/revisions/verify`,
    {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
      body: formData,
    },
  )
}

export function getDocuments(): Promise<DocumentIndexResponse> {
  return apiFetch<DocumentIndexResponse>('/documents')
}

export function getDocument(documentId: number): Promise<DocumentShowResponse> {
  return apiFetch<DocumentShowResponse>(`/documents/${documentId}`)
}

export function getDocumentVerification(
  documentId: number,
): Promise<DocumentVerificationResponse> {
  return apiFetch<DocumentVerificationResponse>(
    `/documents/${documentId}/verification`,
  )
}

export function getDocumentVerificationBundle(
  documentId: number,
): Promise<DocumentVerificationBundleResponse> {
  return apiFetch<DocumentVerificationBundleResponse>(
    `/documents/${documentId}/verification-bundle`,
  )
}

export function getDocumentRevisionVerificationBundle(
  documentId: number,
  revisionId: number,
): Promise<DocumentVerificationBundleResponse> {
  return apiFetch<DocumentVerificationBundleResponse>(
    `/documents/${documentId}/revisions/${revisionId}/verification-bundle`,
  )
}

export async function startDocumentRevisionSign(
  documentId: number,
  revisionId: number,
): Promise<DocumentSensitiveActionOptionsResponse> {
  return apiFetch<DocumentSensitiveActionOptionsResponse>(
    `/documents/${documentId}/revisions/${revisionId}/sign/options`,
    {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': (await getCsrfToken()).csrfToken,
      },
    },
  )
}

export function getDocumentDownloadUrl(documentId: number) {
  return buildApiUrl(`/documents/${documentId}/download`)
}

export function getDocumentRevisionDownloadUrl(
  documentId: number,
  revisionId: number,
) {
  return buildApiUrl(`/documents/${documentId}/revisions/${revisionId}/download`)
}

export function getDocumentVerificationPackageUrl(documentId: number) {
  return buildApiUrl(`/documents/${documentId}/verification-package`)
}

export function getDocumentRevisionVerificationPackageUrl(
  documentId: number,
  revisionId: number,
) {
  return buildApiUrl(
    `/documents/${documentId}/revisions/${revisionId}/verification-package`,
  )
}

const downloadBinary = async (path: string): Promise<ArrayBuffer> => {
  const response = await fetch(buildApiUrl(path), {
    credentials: 'include',
    headers: {
      Accept: 'application/octet-stream',
    },
  })

  if (!response.ok) {
    const contentType = response.headers.get('content-type') || ''
    const payload: unknown = contentType.includes('application/json')
      ? await response.json()
      : await response.text()

    const message =
      payload &&
      typeof payload === 'object' &&
      'message' in payload &&
      typeof payload.message === 'string'
        ? payload.message
        : `Request failed with status ${response.status}`

    throw new ApiRequestError(message, response.status)
  }

  return response.arrayBuffer()
}

export async function downloadDocumentBinary(
  documentId: number,
): Promise<ArrayBuffer> {
  return downloadBinary(`/documents/${documentId}/download`)
}

export async function downloadDocumentRevisionBinary(
  documentId: number,
  revisionId: number,
): Promise<ArrayBuffer> {
  return downloadBinary(`/documents/${documentId}/revisions/${revisionId}/download`)
}

export async function startDocumentSign(
  documentId: number,
): Promise<DocumentSensitiveActionOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DocumentSensitiveActionOptionsResponse>(
    `/documents/${documentId}/sign/options`,
    {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    },
  )
}

export async function verifyDocumentSign(
  documentId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<DocumentSignResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DocumentSignResponse>(`/documents/${documentId}/sign/verify`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyDocumentRevisionSign(
  documentId: number,
  revisionId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<DocumentSignResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DocumentSignResponse>(
    `/documents/${documentId}/revisions/${revisionId}/sign/verify`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    },
  )
}

export async function startDocumentDelete(
  documentId: number,
): Promise<DocumentSensitiveActionOptionsResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DocumentSensitiveActionOptionsResponse>(
    `/documents/${documentId}/delete/options`,
    {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    },
  )
}

export async function verifyDocumentDelete(
  documentId: number,
  payload: WebauthnLoginAssertionPayload,
): Promise<DocumentDeleteResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<DocumentDeleteResponse>(
    `/documents/${documentId}/delete/verify`,
    {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify(payload),
    },
  )
}
