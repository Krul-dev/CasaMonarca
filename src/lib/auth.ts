import { apiFetch, type ApiFieldErrors } from './api'

export type LoginCredentials = {
  email: string
  password: string
}

export type AuthenticatedUser = {
  id: number
  email: string
  name: string
  role?: string | null
}

export type LoginResponse = {
  message: string
  user: AuthenticatedUser
}

export type LoginErrorResponse = {
  errors?: Partial<Record<keyof LoginCredentials, string[]>>
  message: string
}

type CsrfTokenResponse = {
  csrfToken: string
}

export const getFirstFieldError = (
  fieldErrors: ApiFieldErrors | undefined,
  field: keyof LoginCredentials,
) => fieldErrors?.[field]?.[0]

export async function login(
  credentials: LoginCredentials,
): Promise<LoginResponse> {
  const { csrfToken } = await apiFetch<CsrfTokenResponse>('/csrf-token')

  return apiFetch<LoginResponse>('/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken,
    },
    body: JSON.stringify(credentials),
  })
}
