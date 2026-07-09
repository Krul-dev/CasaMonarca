export type ArcoRequestType =
  | 'access'
  | 'rectification'
  | 'cancellation'
  | 'opposition'

export type ArcoRequestStatus =
  | 'opened_by_operator'
  | 'under_review_by_coordinator'
  | 'needs_admin_deletion'
  | 'approved'
  | 'rejected'
  | 'executed'

export type ArcoRequest = {
  id: number
  registry_entry_id: number
  requested_by: number
  requested_by_role: 'operator'
  request_type: ArcoRequestType
  reason: string
  status: ArcoRequestStatus
  escalated_to_admin: boolean
  created_at: string
  updated_at: string
}
