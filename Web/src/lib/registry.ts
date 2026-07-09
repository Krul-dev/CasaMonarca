import { apiFetch, ApiRequestError } from './api'
import type { WebauthnLoginAssertionPayload, WebauthnLoginOptions } from './auth'
import { getCsrfToken } from './csrf'
import type { SecurityChallengeSummary } from './securityChallenges'
import type {
  MigrantRegistrationPayload,
  RegistryEntry,
  RegistrySignature,
} from '../types/registry'

export type {
  MigrantRegistrationPayload,
  RegistryEntry,
  RegistryRole,
  RegistrySignature,
  RegistryStatus,
} from '../types/registry'

export type RegistryListResponse = {
  data: RegistryEntry[]
}

export type RegistryDetailResponse = {
  data: RegistryEntry
  signatures?: RegistrySignature[]
}

export type CreateRegistryEntryPayload = {
  payload_json: MigrantRegistrationPayload
}

export type UpdateRegistryEntryPayload = {
  payload_json: Partial<MigrantRegistrationPayload> & Record<string, unknown>
}

export type SubmitRegistryEntryPayload = {
  public_key_ref?: string
  signature_payload: string
}

export type RegistryApprovalDecision = 'approve' | 'reject'

export type RegistryApprovalOptionsPayload = {
  decision: RegistryApprovalDecision
  reason?: string
}

export type RegistryApprovalOptionsResponse = {
  approvalTarget: {
    decision: RegistryApprovalDecision
    entryId: number
    payloadHash: string
  }
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}

export type RegistryApprovalVerifyResponse = {
  challengeIntent: SecurityChallengeSummary
  data: RegistryEntry
  message: string
}

export async function getRegistryEntries() {
  return apiFetch<RegistryListResponse>('/registry/migrants')
}

export async function getPendingRegistryApprovals() {
  return apiFetch<RegistryListResponse>('/registry/migrants/pending-approval')
}

export async function getRegistryEntryById(id: number) {
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`)
}

export async function createRegistryEntry(payload: CreateRegistryEntryPayload) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryDetailResponse>('/registry/migrants', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function updateRegistryEntry(
  id: number,
  payload: UpdateRegistryEntryPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function submitRegistryEntry(
  id: number,
  payload: SubmitRegistryEntryPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}/submit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function startRegistryApproval(
  id: number,
  payload: RegistryApprovalOptionsPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryApprovalOptionsResponse>(
    `/registry/migrants/${id}/approval/options`,
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

export async function verifyRegistryApproval(
  id: number,
  payload: WebauthnLoginAssertionPayload,
) {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<RegistryApprovalVerifyResponse>(
    `/registry/migrants/${id}/approval/verify`,
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

export { ApiRequestError }
