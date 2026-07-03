import { apiFetch } from './api'
import type { UserRole } from './auth'
import { getCsrfToken } from './csrf'

export type InviteRole = 'admin' | 'coordinator' | 'non_coordinator' | 'volunteer'
export type InviteStatus =
  | 'draft'
  | 'verified'
  | 'issued'
  | 'expired'
  | 'redeemed'
  | 'revoked'

export type InviteVerificationMethod = 'phone' | 'in_person'

export type AccountInviteSummary = {
  createdAt?: string | null
  email: string
  expiresAt?: string | null
  id: number
  invitedBy: {
    email?: string | null
    id?: number | null
    role?: UserRole | null
  }
  issuedAt?: string | null
  revokedAt?: string | null
  role: InviteRole
  status: InviteStatus | string
  usedAt?: string | null
  verificationMethod?: string | null
  verifiedBy: {
    email?: string | null
    id?: number | null
    role?: UserRole | null
  }
  verifiedOutOfBandAt?: string | null
}

export type AccountInviteListResponse = {
  invites: AccountInviteSummary[]
  message: string
}

export type AccountInviteCreateResponse = {
  invite: AccountInviteSummary & {
    verificationRequired: boolean
  }
  message: string
}

export type AccountInviteVerifyResponse = {
  invite: AccountInviteSummary
  message: string
}

export type AccountInviteIssueLinkResponse = {
  invite: AccountInviteSummary & {
    registrationPath: string
    registrationToken: string
  }
  message: string
}

export type AccountInviteRevokeResponse = {
  invite: AccountInviteSummary
  message: string
}

export type AccountInviteCreatePayload = {
  email: string
  role: InviteRole
}

export type AccountInviteVerifyPayload = {
  method: InviteVerificationMethod
  note?: string
}

export type AccountInviteIssueLinkPayload = {
  expiresInHours?: number
}

export function getAccountInvites(limit = 25): Promise<AccountInviteListResponse> {
  const params = new URLSearchParams()
  params.set('limit', String(limit))

  return apiFetch<AccountInviteListResponse>(`/admin/invites?${params.toString()}`)
}

export async function createAccountInvite(
  payload: AccountInviteCreatePayload,
): Promise<AccountInviteCreateResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AccountInviteCreateResponse>('/admin/invites', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function verifyAccountInviteOutOfBand(
  inviteId: number,
  payload: AccountInviteVerifyPayload,
): Promise<AccountInviteVerifyResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AccountInviteVerifyResponse>(`/admin/invites/${inviteId}/verify-out-of-band`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function issueAccountInviteLink(
  inviteId: number,
  payload: AccountInviteIssueLinkPayload = {},
): Promise<AccountInviteIssueLinkResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AccountInviteIssueLinkResponse>(`/admin/invites/${inviteId}/issue-link`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}

export async function revokeAccountInvite(
  inviteId: number,
): Promise<AccountInviteRevokeResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<AccountInviteRevokeResponse>(`/admin/invites/${inviteId}/revoke`, {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': csrfToken,
    },
  })
}

export type InviteRedeemResponse = {
  enrollment: {
    requiresPasskey: boolean
    requiresTotp: boolean
  }
  message: string
  user: {
    email: string
    id: number
    name: string
    role: UserRole
  }
}

export type InvitePreviewResponse = {
  enrollment: {
    requiresPasskey: boolean
    requiresTotp: boolean
  }
  invite: {
    email: string
    expiresAt?: string | null
    role: UserRole
    status: InviteStatus | string
  }
  message: string
}

export type InviteRedeemPayload = {
  email: string
  name: string
  password: string
  password_confirmation: string
  token: string
}

export function previewInvite(token: string): Promise<InvitePreviewResponse> {
  const params = new URLSearchParams()
  params.set('token', token)

  return apiFetch<InvitePreviewResponse>(`/invites/preview?${params.toString()}`)
}

export async function redeemInvite(
  payload: InviteRedeemPayload,
): Promise<InviteRedeemResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<InviteRedeemResponse>('/invites/redeem', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(payload),
  })
}
