import type {
  WebauthnLoginAssertionPayload,
  WebauthnLoginOptions,
} from './auth'

const base64UrlToArrayBuffer = (value: string): ArrayBuffer => {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(raw.length)

  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index)
  }

  return bytes.buffer
}

const arrayBufferToBase64Url = (buffer: ArrayBuffer): string => {
  const bytes = new Uint8Array(buffer)
  let binary = ''

  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

export const isIpHostname = (hostname: string): boolean => {
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(hostname)) {
    return true
  }

  return hostname.includes(':')
}

export async function getWebauthnAssertion(
  options: WebauthnLoginOptions,
): Promise<WebauthnLoginAssertionPayload> {
  if (
    !window.isSecureContext ||
    !('PublicKeyCredential' in window) ||
    !('credentials' in navigator)
  ) {
    throw new Error(
      'WebAuthn is only available in a secure context and supported browser.',
    )
  }

  const credential = await navigator.credentials.get({
    publicKey: {
      ...options,
      challenge: base64UrlToArrayBuffer(options.challenge),
      allowCredentials: options.allowCredentials?.map((credentialOption) => ({
        ...credentialOption,
        id: base64UrlToArrayBuffer(credentialOption.id),
        transports: credentialOption.transports ?? undefined,
      })),
    },
  })

  if (!(credential instanceof PublicKeyCredential)) {
    throw new Error('The security key did not return a valid WebAuthn assertion.')
  }

  const response = credential.response

  if (!(response instanceof AuthenticatorAssertionResponse)) {
    throw new Error('WebAuthn assertion response is invalid for this action.')
  }

  return {
    id: credential.id,
    rawId: arrayBufferToBase64Url(credential.rawId),
    type: 'public-key',
    response: {
      authenticatorData: arrayBufferToBase64Url(response.authenticatorData),
      clientDataJSON: arrayBufferToBase64Url(response.clientDataJSON),
      signature: arrayBufferToBase64Url(response.signature),
      userHandle: response.userHandle
        ? arrayBufferToBase64Url(response.userHandle)
        : undefined,
    },
  }
}
