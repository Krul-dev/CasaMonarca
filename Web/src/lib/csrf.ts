import { apiFetch } from './api'

type CsrfTokenResponse = {
  csrfToken: string
}

export async function getCsrfToken() {
  return apiFetch<CsrfTokenResponse>('/csrf-token')
}
