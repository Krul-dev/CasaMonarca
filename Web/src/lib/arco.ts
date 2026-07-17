import type { WebauthnLoginAssertionPayload } from './auth'
import { apiFetch, buildApiUrl } from './api'
import { getCsrfToken } from './csrf'
import type { ArcoChallengeResponse, ArcoDecision, ArcoRequest, ArcoRequestType } from '../types/arco'
import type { MigrantRegistrationPayload } from '../types/registry'

export type CreateArcoIntent = {
  proposedPayload?: MigrantRegistrationPayload
  reason: string
  registryEntryId: number
  requestType: ArcoRequestType
}

export type ArcoDecisionIntent = { decision: ArcoDecision; reason?: string }
export type ArcoListResponse = { data: ArcoRequest[] }
export type ArcoMutationResponse = { data: ArcoRequest; message: string }

const post = async <T>(path: string, payload: unknown) => {
  const { csrfToken } = await getCsrfToken()
  return apiFetch<T>(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
    body: JSON.stringify(payload),
  })
}

export const getArcoRequests = () => apiFetch<ArcoListResponse>('/registry/migrants/arco')
export const startArcoRequest = (payload: CreateArcoIntent) => post<ArcoChallengeResponse>('/registry/migrants/arco/create/options', payload)
export const verifyArcoRequest = (assertion: WebauthnLoginAssertionPayload) => post<ArcoMutationResponse>('/registry/migrants/arco/create/verify', assertion)
export const startArcoDecision = (id: number, stage: 'coordinator' | 'admin', payload: ArcoDecisionIntent) =>
  post<ArcoChallengeResponse>(`/registry/migrants/arco/${id}/${stage}-decision/options`, payload)
export const verifyArcoDecision = (id: number, stage: 'coordinator' | 'admin', assertion: WebauthnLoginAssertionPayload) =>
  post<ArcoMutationResponse>(`/registry/migrants/arco/${id}/${stage}-decision/verify`, assertion)
export const getArcoAccessDocumentUrl = (id: number) => buildApiUrl(`/registry/migrants/arco/${id}/access-document`)
