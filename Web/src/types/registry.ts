export type RegistryRole = 'volunteer' | 'operator' | 'coordinator' | 'admin'

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
