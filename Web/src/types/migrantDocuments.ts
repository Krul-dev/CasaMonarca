import type { UserRole } from '../lib/auth'

export type MigrantDocumentUploader = {
  email?: string | null
  id: number
  name?: string | null
  role?: UserRole | null
}

export type MigrantDocument = {
  arco_access_completed: boolean
  created_at: string
  id: number
  label?: string | null
  mime_type?: string | null
  original_file_name: string
  registry_entry_id: number
  sha256: string
  size_bytes: number
  updated_at: string
  uploaded_by?: number | null
  uploaded_by_role: UserRole
  uploader?: MigrantDocumentUploader | null
}
