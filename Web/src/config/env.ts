const trimTrailingSlash = (value: string) => value.replace(/\/+$/, '')

export const appName = import.meta.env.VITE_APP_NAME || 'CasaMonarca Web'
export const appChannel = import.meta.env.VITE_APP_CHANNEL?.trim() || null
export const arcoEnabled = import.meta.env.VITE_ARCO_ENABLED !== 'false'
export const arcoEnabledTypes = (import.meta.env.VITE_ARCO_ENABLED_TYPES || 'access')
  .split(',')
  .map((value: string) => value.trim())
  .filter(Boolean)
export const apiBaseUrl = trimTrailingSlash(
  import.meta.env.VITE_API_BASE_URL || '/api',
)
