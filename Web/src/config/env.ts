const trimTrailingSlash = (value: string) => value.replace(/\/+$/, '')

export const appName = import.meta.env.VITE_APP_NAME || 'CasaMonarca Web'
export const appChannel = import.meta.env.VITE_APP_CHANNEL?.trim() || null
export const apiBaseUrl = trimTrailingSlash(
  import.meta.env.VITE_API_BASE_URL || '/api',
)
