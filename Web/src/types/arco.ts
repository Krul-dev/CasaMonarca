import type { WebauthnLoginOptions } from '../lib/auth'
import type { SecurityChallengeSummary } from '../lib/securityChallenges'
import type { MigrantRegistrationPayload, RegistryEntry, RegistryRole } from './registry'

export type ArcoRequestType = 'access' | 'rectification' | 'cancellation' | 'opposition'
export type ArcoRequestStatus = 'pending_coordinator' | 'pending_admin' | 'completed' | 'rejected'
export type ArcoDecision = 'approve' | 'reject'

export type ArcoSignature = {
  action_type: string
  actor?: { email: string; id: number; name: string; role: RegistryRole } | null
  actor_role: RegistryRole
  actor_user_id: number | null
  id: number
  public_key_ref?: string | null
  verified_at: string
}

export type ArcoStatusHistory = {
  created_at: string
  from_status: string | null
  id: number
  reason?: string | null
  to_status: string
}

export type ArcoArtifact = {
  byte_size: number
  filename: string
  generated_at: string
  id: number
  mime_type: string
  purged_at?: string | null
  sha256: string
}

export type ArcoRequest = {
  artifact?: ArcoArtifact | null
  completed_at?: string | null
  created_at: string
  escalated_to_admin: boolean
  id: number
  original_payload_json?: MigrantRegistrationPayload | null
  proposed_payload_json?: MigrantRegistrationPayload | null
  reason: string
  registry_entry?: RegistryEntry | null
  registry_entry_id: number
  request_type: ArcoRequestType
  requested_by: number
  requested_by_role: RegistryRole
  requester?: { email: string; id: number; name: string; role: RegistryRole } | null
  resolution_reason?: string | null
  signatures?: ArcoSignature[]
  status: ArcoRequestStatus
  status_history?: ArcoStatusHistory[]
  updated_at: string
}

export type ArcoChallengeResponse = {
  challengeIntent: SecurityChallengeSummary
  message: string
  options: WebauthnLoginOptions
}
