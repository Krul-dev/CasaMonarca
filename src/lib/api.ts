import { apiBaseUrl } from '../config/env'

export type ApiHealthResponse = {
  service: string
  status: string
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
    const message =
      payload &&
      typeof payload === 'object' &&
      'message' in payload &&
      typeof payload.message === 'string'
        ? payload.message
        : `Request failed with status ${response.status}`

    throw new Error(message)
  }

  return payload as T
}

export function getApiHealth() {
  return apiFetch<ApiHealthResponse>('/health')
}
