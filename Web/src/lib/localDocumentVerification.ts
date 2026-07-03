import type {
  DocumentVerificationBundle,
  DocumentVerificationBundleSignature,
} from './documents'

type LocalVerificationChecks = {
  challengeMatches: boolean
  clientDataChallenge: boolean
  clientDataOrigin: boolean
  clientDataType: boolean
  cryptographicSignature: boolean
  documentHashMatches: boolean
  intentCanonical: boolean
  revisionMatches: boolean
  rpIdHash: boolean
  userPresent: boolean
}

export type LocalSignatureVerificationResult = {
  checks: LocalVerificationChecks
  message: string
  signatureId: number
  verified: boolean
}

export type LocalDocumentVerificationReport = {
  fileHash: string
  revisionHash: string | null
  signatures: LocalSignatureVerificationResult[]
  verified: boolean
}

const encoder = new TextEncoder()
const decoder = new TextDecoder()

const isArrayBuffer = (value: unknown): value is ArrayBuffer =>
  Object.prototype.toString.call(value) === '[object ArrayBuffer]'

const base64UrlToBytes = (value: string) => {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(raw.length)

  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index)
  }

  return bytes
}

const bytesToBase64Url = (value: ArrayBuffer | Uint8Array) => {
  const bytes = value instanceof Uint8Array ? value : new Uint8Array(value)
  let binary = ''

  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const bytesToHex = (value: Uint8Array) =>
  Array.from(value)
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')

const arraysEqual = (left: Uint8Array, right: Uint8Array) => {
  if (left.length !== right.length) {
    return false
  }

  return left.every((byte, index) => byte === right[index])
}

const concatBytes = (...parts: Uint8Array[]) => {
  const totalLength = parts.reduce((sum, part) => sum + part.length, 0)
  const result = new Uint8Array(totalLength)
  let offset = 0

  parts.forEach((part) => {
    result.set(part, offset)
    offset += part.length
  })

  return result
}

const readDerLength = (bytes: Uint8Array, offset: number) => {
  if (offset >= bytes.length) {
    throw new Error('Invalid DER signature length.')
  }

  const firstByte = bytes[offset]

  if ((firstByte & 0x80) === 0) {
    return {
      length: firstByte,
      nextOffset: offset + 1,
    }
  }

  const lengthBytes = firstByte & 0x7f

  if (lengthBytes === 0 || lengthBytes > 4 || offset + 1 + lengthBytes > bytes.length) {
    throw new Error('Invalid DER signature length.')
  }

  let length = 0

  for (let index = 0; index < lengthBytes; index += 1) {
    length = (length << 8) | bytes[offset + 1 + index]
  }

  return {
    length,
    nextOffset: offset + 1 + lengthBytes,
  }
}

const normalizeDerInteger = (bytes: Uint8Array, size: number) => {
  let offset = 0

  while (offset < bytes.length - 1 && bytes[offset] === 0x00) {
    offset += 1
  }

  const value = bytes.slice(offset)

  if (value.length > size) {
    throw new Error('Invalid DER integer size in signature.')
  }

  const normalized = new Uint8Array(size)
  normalized.set(value, size - value.length)

  return normalized
}

const derEcdsaSignatureToRaw = (signature: Uint8Array, coordinateSize: number) => {
  if (signature.length === coordinateSize * 2) {
    return signature
  }

  if (signature.length < 8 || signature[0] !== 0x30) {
    throw new Error('The ECDSA signature is not valid DER.')
  }

  const sequenceLength = readDerLength(signature, 1)
  const offset = sequenceLength.nextOffset
  const sequenceEnd = offset + sequenceLength.length

  if (sequenceEnd !== signature.length || offset >= sequenceEnd || signature[offset] !== 0x02) {
    throw new Error('The ECDSA signature sequence is invalid.')
  }

  const rLength = readDerLength(signature, offset + 1)
  const rStart = rLength.nextOffset
  const rEnd = rStart + rLength.length

  if (rEnd > sequenceEnd || rStart >= rEnd || rEnd >= sequenceEnd || signature[rEnd] !== 0x02) {
    throw new Error('The ECDSA signature R component is invalid.')
  }

  const sLength = readDerLength(signature, rEnd + 1)
  const sStart = sLength.nextOffset
  const sEnd = sStart + sLength.length

  if (sEnd !== sequenceEnd || sStart >= sEnd) {
    throw new Error('The ECDSA signature S component is invalid.')
  }

  const r = normalizeDerInteger(signature.slice(rStart, rEnd), coordinateSize)
  const s = normalizeDerInteger(signature.slice(sStart, sEnd), coordinateSize)

  return concatBytes(r, s)
}

const normalizeValue = (value: unknown): unknown => {
  if (Array.isArray(value)) {
    return value.map(normalizeValue)
  }

  if (value && typeof value === 'object') {
    return Object.keys(value as Record<string, unknown>)
      .sort()
      .reduce<Record<string, unknown>>((accumulator, key) => {
        accumulator[key] = normalizeValue((value as Record<string, unknown>)[key])
        return accumulator
      }, {})
  }

  return value
}

const toCanonicalJson = (value: Record<string, unknown>) =>
  JSON.stringify(normalizeValue(value))

const sha256Bytes = async (value: BufferSource | string) => {
  const input =
    typeof value === 'string'
      ? encoder.encode(value)
      : isArrayBuffer(value)
        ? value
        : ArrayBuffer.isView(value)
          ? value.buffer.slice(
              value.byteOffset,
              value.byteOffset + value.byteLength,
            )
          : value

  return new Uint8Array(await crypto.subtle.digest('SHA-256', input))
}

const buildEmptyChecks = (): LocalVerificationChecks => ({
  challengeMatches: false,
  clientDataChallenge: false,
  clientDataOrigin: false,
  clientDataType: false,
  cryptographicSignature: false,
  documentHashMatches: false,
  intentCanonical: false,
  revisionMatches: false,
  rpIdHash: false,
  userPresent: false,
})

const importPublicKey = async (
  signature: DocumentVerificationBundleSignature,
): Promise<{
  key: CryptoKey
  normalizedSignature?: (signature: Uint8Array) => Uint8Array
  verifyAlgorithm: AlgorithmIdentifier | RsaPssParams | EcdsaParams
}> => {
  const publicKey = signature.credential.publicKey
  const publicKeyAlgorithm = signature.credential.publicKeyAlgorithm

  if (!publicKey || publicKeyAlgorithm == null) {
    throw new Error('The signature does not expose a public key for local verification.')
  }

  const spki = base64UrlToBytes(publicKey)

  switch (publicKeyAlgorithm) {
    case -7: {
      const importAlgorithm: EcKeyImportParams = {
        name: 'ECDSA',
        namedCurve: 'P-256',
      }

      const key = await crypto.subtle.importKey(
        'spki',
        spki,
        importAlgorithm,
        false,
        ['verify'],
      )

      return {
        key,
        normalizedSignature: (signature) =>
          derEcdsaSignatureToRaw(signature, 32),
        verifyAlgorithm: {
          name: 'ECDSA',
          hash: 'SHA-256',
        },
      }
    }
    case -257: {
      const importAlgorithm: RsaHashedImportParams = {
        name: 'RSASSA-PKCS1-v1_5',
        hash: 'SHA-256',
      }

      const key = await crypto.subtle.importKey(
        'spki',
        spki,
        importAlgorithm,
        false,
        ['verify'],
      )

      return {
        key,
        verifyAlgorithm: {
          name: 'RSASSA-PKCS1-v1_5',
        },
      }
    }
    default:
      throw new Error(`Unsupported public key algorithm: ${publicKeyAlgorithm}`)
  }
}

const verifySignatureLocally = async (
  bundle: DocumentVerificationBundle,
  signature: DocumentVerificationBundleSignature,
  fileHash: string,
) => {
  const checks = buildEmptyChecks()
  const intent = signature.intent
  const assertion = signature.assertion

  if (!intent || !assertion?.response) {
    return {
      checks,
      message:
        'This signature does not expose a complete verification bundle yet.',
      signatureId: signature.id,
      verified: false,
    } satisfies LocalSignatureVerificationResult
  }

  try {
    const canonicalIntent = toCanonicalJson(intent as Record<string, unknown>)
    const derivedChallenge = bytesToBase64Url(await sha256Bytes(canonicalIntent))

    checks.intentCanonical = signature.canonicalIntent === canonicalIntent
    checks.challengeMatches = signature.challenge === derivedChallenge
    checks.revisionMatches =
      intent.documentId === bundle.document.id &&
      intent.revisionId === bundle.revision.id &&
      intent.revisionNumber === bundle.revision.number &&
      intent.revisionSha256 === bundle.revision.sha256
    checks.documentHashMatches =
      signature.documentHash === fileHash &&
      bundle.revision.sha256 === fileHash &&
      intent.revisionSha256 === fileHash

    const clientDataRaw = base64UrlToBytes(
      assertion.response.clientDataJSON ?? '',
    )
    const clientData = JSON.parse(
      decoder.decode(clientDataRaw),
    ) as Record<string, unknown>

    checks.clientDataType = clientData.type === 'webauthn.get'
    checks.clientDataOrigin = clientData.origin === intent.origin
    checks.clientDataChallenge = clientData.challenge === derivedChallenge

    const authenticatorDataRaw = base64UrlToBytes(
      assertion.response.authenticatorData ?? '',
    )

    if (authenticatorDataRaw.length < 37) {
      throw new Error('Authenticator data is too short for local verification.')
    }

    const rpIdHash = authenticatorDataRaw.slice(0, 32)
    const expectedRpIdHash = await sha256Bytes(String(intent.rpId ?? ''))
    checks.rpIdHash = arraysEqual(rpIdHash, expectedRpIdHash)
    checks.userPresent = (authenticatorDataRaw[32] & 0x01) !== 0

    const clientDataHash = await sha256Bytes(clientDataRaw)
    const verificationData = concatBytes(authenticatorDataRaw, clientDataHash)
    const signatureRaw = base64UrlToBytes(assertion.response.signature ?? '')
    const { key, normalizedSignature, verifyAlgorithm } = await importPublicKey(
      signature,
    )
    const signatureForVerification = normalizedSignature
      ? normalizedSignature(signatureRaw)
      : signatureRaw

    checks.cryptographicSignature = await crypto.subtle.verify(
      verifyAlgorithm,
      key,
      new Uint8Array(signatureForVerification),
      new Uint8Array(verificationData),
    )

    const verified = Object.values(checks).every(Boolean)

    return {
      checks,
      message: verified
        ? 'Signature verified locally against the downloaded revision.'
        : 'One or more local verification checks failed.',
      signatureId: signature.id,
      verified,
    } satisfies LocalSignatureVerificationResult
  } catch (error) {
    return {
      checks,
      message:
        error instanceof Error
          ? error.message
          : 'Local verification failed unexpectedly.',
      signatureId: signature.id,
      verified: false,
    } satisfies LocalSignatureVerificationResult
  }
}

export async function verifyDocumentBundleLocally(
  bundle: DocumentVerificationBundle,
  fileBytes: ArrayBuffer,
): Promise<LocalDocumentVerificationReport> {
  const fileHash = bytesToHex(await sha256Bytes(fileBytes))
  const signatures = await Promise.all(
    bundle.signatures.map((signature) =>
      verifySignatureLocally(bundle, signature, fileHash),
    ),
  )

  return {
    fileHash,
    revisionHash: bundle.revision.sha256,
    signatures,
    verified:
      signatures.length > 0 && signatures.every((signature) => signature.verified),
  }
}
