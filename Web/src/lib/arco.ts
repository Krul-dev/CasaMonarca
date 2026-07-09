import { apiFetch } from './api'
import type { ArcoRequest, ArcoRequestType } from '../types/arco'

export type CreateArcoRequestPayload = {
  registry_entry_id: number
  request_type: ArcoRequestType
  reason: string
}

export type ResolveArcoRequestPayload = {
  decision: 'approve' | 'reject'
  reason?: string
  needs_admin_deletion?: boolean
}

export type ArcoListResponse = {
  data: ArcoRequest[]
}

export async function getArcoRequests() {
  return apiFetch<ArcoListResponse>('/registry/migrants/arco')
}

export async function createArcoRequest(payload: CreateArcoRequestPayload) {
  return apiFetch<ArcoRequest>('/registry/migrants/arco', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
}

export async function resolveArcoRequest(
  id: number,
  payload: ResolveArcoRequestPayload,
) {
  return apiFetch<ArcoRequest>(`/registry/migrants/arco/${id}/resolve`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
    },
    body: JSON.stringify(payload),
  })
}

export async function escalateArcoToAdmin(id: number) {
  return apiFetch<ArcoRequest>(`/registry/migrants/arco/${id}/escalate-admin`, {
    method: 'POST',
  })
}

export async function deleteRegistryEntryByAdmin(id: number) {
  return apiFetch<void>(`/registry/migrants/${id}`, {
    method: 'DELETE',
  })
}
