import { apiFetch, ApiRequestError } from './api'

export type RegistryRole = 'volunteer' | 'non_coordinator' | 'coordinator' | 'admin'

export type RegistryStatus =
  | 'draft'
  | 'submitted_by_volunteer'
  | 'reviewed_by_operator'
  | 'approved_by_operator'
  | 'rejected_by_operator'
  | 'sent_to_coordinator'
  | 'edited_by_coordinator'
  | 'approved_by_coordinator'
  | 'arco_requested'
  | 'arco_approved'
  | 'arco_rejected'
  | 'sent_to_admin_for_deletion'
  | 'deleted_by_admin'

export type RegistryEntry = {
  id: number
  created_by: number
  created_by_role: RegistryRole
  current_status: RegistryStatus
  payload_json: Record<string, unknown>
  created_at: string
  updated_at: string
}

export type RegistrySignature = {
  id: number
  registry_entry_id: number
  actor_user_id: number
  actor_role: RegistryRole
  action_type: string
  algorithm: string
  signature_payload: string
  public_key_ref?: string | null
  verified_at?: string | null
  created_at: string
}

export type RegistryListResponse = {
  data: RegistryEntry[]
}

export type RegistryDetailResponse = {
  data: RegistryEntry
  signatures: RegistrySignature[]
}

export type CreateRegistryEntryPayload = {
  payload_json: Record<string, unknown>
}

export type UpdateRegistryEntryPayload = {
  payload_json: Record<string, unknown>
}

export type SubmitRegistryEntryPayload = {
  signature_payload: string
  public_key_ref?: string
}

export type ReviewRegistryEntryPayload = {
  decision: 'approve' | 'reject'
  reason?: string
  signature_payload: string
  public_key_ref?: string
}

export async function getRegistryEntries() {
  return apiFetch<RegistryListResponse>('/registry/migrants')
}

export async function getRegistryEntryById(id: number) {
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`)
}

export async function createRegistryEntry(payload: CreateRegistryEntryPayload) {
  return apiFetch<RegistryDetailResponse>('/registry/migrants', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
}

export async function updateRegistryEntry(
  id: number,
  payload: UpdateRegistryEntryPayload,
) {
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}`, {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
}

export async function submitRegistryEntry(
  id: number,
  payload: SubmitRegistryEntryPayload,
) {
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}/submit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
}

export async function reviewRegistryEntry(
  id: number,
  payload: ReviewRegistryEntryPayload,
) {
  return apiFetch<RegistryDetailResponse>(`/registry/migrants/${id}/review`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
}

export { ApiRequestError }
