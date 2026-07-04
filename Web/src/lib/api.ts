import { apiBaseUrl } from '../config/env'

export type ApiHealthResponse = {
  service: string
  status: string
}

export type ApiFieldErrors = Record<string, string[]>

export class ApiRequestError extends Error {
  readonly errors?: ApiFieldErrors
  readonly status: number

  constructor(message: string, status: number, errors?: ApiFieldErrors) {
    super(message)
    this.name = 'ApiRequestError'
    this.status = status
    this.errors = errors
  }
}

const buildApiUrl = (path: string) => {
  const normalizedPath = path.startsWith('/') ? path : `/${path}`

  return `${apiBaseUrl}${normalizedPath}`
}

export async function apiFetch<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const response = await fetch(buildApiUrl(path), {
    ...init,
    credentials: init.credentials ?? 'include',
    headers: {
      Accept: 'application/json',
      ...init.headers,
    },
  })

  const contentType = response.headers.get('content-type') || ''
  const payload: unknown = contentType.includes('application/json')
    ? await response.json()
    : await response.text()

  if (!response.ok) {
    const errors =
      payload &&
      typeof payload === 'object' &&
      'errors' in payload &&
      payload.errors &&
      typeof payload.errors === 'object'
        ? (payload.errors as ApiFieldErrors)
        : undefined

    const message =
      payload &&
      typeof payload === 'object' &&
      'message' in payload &&
      typeof payload.message === 'string'
        ? payload.message
        : `Request failed with status ${response.status}`

    throw new ApiRequestError(message, response.status, errors)
  }

  return payload as T
}

export function getApiHealth() {
  return apiFetch<ApiHealthResponse>('/health')
}
