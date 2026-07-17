export type SignatureValidityState = {
  countdownLabel: string
  expired: boolean
  expiresAt: string | null
  remainingMs: number | null
}

const parseDate = (value?: string | null) => {
  if (!value) {
    return null
  }

  const parsed = new Date(value)

  return Number.isNaN(parsed.getTime()) ? null : parsed
}

const formatDuration = (valueMs: number) => {
  const totalSeconds = Math.max(0, Math.floor(Math.abs(valueMs) / 1000))
  const days = Math.floor(totalSeconds / 86400)
  const hours = Math.floor((totalSeconds % 86400) / 3600)
  const minutes = Math.floor((totalSeconds % 3600) / 60)
  const seconds = totalSeconds % 60

  const parts = [`${seconds}s`]

  if (days > 0 || hours > 0 || minutes > 0) {
    parts.unshift(`${minutes}m`)
  }

  if (days > 0 || hours > 0) {
    parts.unshift(`${hours}h`)
  }

  if (days > 0) {
    parts.unshift(`${days}d`)
  }

  return parts.join(' ')
}

export const getSignatureValidityState = (
  expiresAt?: string | null,
  nowMs = Date.now(),
): SignatureValidityState => {
  const expiresAtDate = parseDate(expiresAt)

  if (expiresAtDate == null) {
    return {
      countdownLabel: 'Not available',
      expired: false,
      expiresAt: null,
      remainingMs: null,
    }
  }

  const remainingMs = expiresAtDate.getTime() - nowMs
  const expired = remainingMs <= 0

  return {
    countdownLabel: expired
      ? `Expired ${formatDuration(remainingMs)} ago`
      : formatDuration(remainingMs),
    expired,
    expiresAt: expiresAtDate.toISOString(),
    remainingMs,
  }
}
