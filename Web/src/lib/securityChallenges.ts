import { apiFetch } from './api'
import { getCsrfToken } from './csrf'

export type SecurityChallengeCancelResponse = {
  challengeIntent: {
    id: string
    status: string
  }
  message: string
}

export type SecurityChallengeSummary = {
  expiresAt?: string | null
  id: string
  purpose: string
  status: string
}

export async function cancelSecurityChallenge(
  challengeIntentId: string,
): Promise<SecurityChallengeCancelResponse> {
  const { csrfToken } = await getCsrfToken()

  return apiFetch<SecurityChallengeCancelResponse>(
    `/security-challenges/${encodeURIComponent(challengeIntentId)}/cancel`,
    {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken,
      },
    },
  )
}
