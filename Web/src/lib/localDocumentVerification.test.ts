import { describe, expect, it } from 'vitest'

import type { DocumentVerificationBundle } from './documents'
import { verifyDocumentBundleLocally } from './localDocumentVerification'

const encoder = new TextEncoder()

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

const trimUnsignedInteger = (value: Uint8Array) => {
  let offset = 0

  while (offset < value.length - 1 && value[offset] === 0x00) {
    offset += 1
  }

  const trimmed = value.slice(offset)

  if ((trimmed[0] & 0x80) !== 0) {
    return concatBytes(Uint8Array.of(0x00), trimmed)
  }

  return trimmed
}

const encodeDerLength = (length: number) => {
  if (length < 0x80) {
    return Uint8Array.of(length)
  }

  const bytes: number[] = []
  let remaining = length

  while (remaining > 0) {
    bytes.unshift(remaining & 0xff)
    remaining >>= 8
  }

  return Uint8Array.of(0x80 | bytes.length, ...bytes)
}

const rawP256SignatureToDer = (value: ArrayBuffer) => {
  const raw = new Uint8Array(value)

  if (raw.length !== 64) {
    throw new Error('Se esperaba una firma P-256 sin procesar de 64 bytes.')
  }

  const r = trimUnsignedInteger(raw.slice(0, 32))
  const s = trimUnsignedInteger(raw.slice(32))
  const derBody = concatBytes(
    Uint8Array.of(0x02),
    encodeDerLength(r.length),
    r,
    Uint8Array.of(0x02),
    encodeDerLength(s.length),
    s,
  )

  return concatBytes(Uint8Array.of(0x30), encodeDerLength(derBody.length), derBody)
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
      : value instanceof ArrayBuffer
        ? value
        : value.buffer.slice(value.byteOffset, value.byteOffset + value.byteLength)

  return new Uint8Array(await crypto.subtle.digest('SHA-256', input))
}

async function createBundleFixture(signerCurp: string | null | 'legacy' = 'SABC560626MDFLRN01') {
  const fileBytes = encoder.encode('Carga útil de la revisión del documento Casa Monarca.')
  const fileHash = bytesToHex(await sha256Bytes(fileBytes))

  const intent = {
    documentId: 15,
    expiresAt: '2026-04-22T18:15:00Z',
    issuedAt: '2026-04-22T18:10:00Z',
    nonce: 'intent-nonce-123',
    origin: 'https://casamonarca.muchosnumeros.online',
    purpose: 'document.sign',
    revisionId: 30,
    revisionNumber: 4,
    revisionSha256: fileHash,
    rpId: 'casamonarca.muchosnumeros.online',
    ...(signerCurp === 'legacy' ? {} : { signerCurp }),
    userId: 3,
    version: signerCurp === 'legacy' ? 1 : 2,
  }

  const canonicalIntent = toCanonicalJson(intent)
  const challenge = bytesToBase64Url(await sha256Bytes(canonicalIntent))

  const clientDataJson = JSON.stringify({
    challenge,
    origin: intent.origin,
    type: 'webauthn.get',
  })
  const clientDataJsonBytes = encoder.encode(clientDataJson)
  const rpIdHash = await sha256Bytes(intent.rpId)
  const authenticatorData = concatBytes(
    rpIdHash,
    Uint8Array.of(0x01),
    Uint8Array.of(0x00, 0x00, 0x00, 0x09),
  )
  const clientDataHash = await sha256Bytes(clientDataJsonBytes)
  const verificationData = concatBytes(authenticatorData, clientDataHash)

  const keyPair = await crypto.subtle.generateKey(
    {
      name: 'ECDSA',
      namedCurve: 'P-256',
    },
    true,
    ['sign', 'verify'],
  )

  const signatureBytes = await crypto.subtle.sign(
    {
      name: 'ECDSA',
      hash: 'SHA-256',
    },
    keyPair.privateKey,
    verificationData,
  )
  const webauthnSignatureBytes = rawP256SignatureToDer(signatureBytes)

  const publicKeySpki = await crypto.subtle.exportKey('spki', keyPair.publicKey)

  const bundle: DocumentVerificationBundle = {
    document: {
      id: intent.documentId,
      title: 'Carta de admisión legal',
    },
    revision: {
      id: intent.revisionId,
      number: intent.revisionNumber,
      sha256: fileHash,
      signatureStatus: 'signed',
    },
    signatures: [
      {
        assertion: {
          id: 'credential-local-1',
          rawId: bytesToBase64Url(Uint8Array.of(1, 2, 3, 4)),
          response: {
            authenticatorData: bytesToBase64Url(authenticatorData),
            clientDataJSON: bytesToBase64Url(clientDataJsonBytes),
            signature: bytesToBase64Url(webauthnSignatureBytes),
            userHandle: null,
          },
          type: 'public-key',
        },
        canonicalIntent,
        challenge,
        credential: {
          id: 'credential-local-1',
          name: 'Llave de seguridad de coordinación',
          publicKey: bytesToBase64Url(publicKeySpki),
          publicKeyAlgorithm: -7,
          publicKeyFingerprintSha256: 'unused-in-test',
          publicKeyFormat: 'spki',
          signCount: 9,
        },
        documentHash: fileHash,
        id: 99,
        intent,
        signatureType: 'webauthn-passkey',
        signedAt: '2026-04-22T18:11:00Z',
        signedBy: {
          id: intent.userId,
          name: 'Coordinación local',
          email: 'coordinator@casamonarca.local',
          curp: signerCurp === 'legacy' ? null : signerCurp,
        },
        verificationStatus: 'verified',
      },
    ],
    version: 1,
  }

  return {
    bundle,
    fileBytes,
  }
}

describe('verifyDocumentBundleLocally', () => {
  it('verifica un paquete almacenado contra la revisión descargada', async () => {
    const { bundle, fileBytes } = await createBundleFixture()

    const report = await verifyDocumentBundleLocally(bundle, fileBytes.buffer)

    expect(report.verified).toBe(true)
    expect(report.fileHash).toBe(bundle.revision.sha256)
    expect(report.signatures).toHaveLength(1)
    expect(report.signatures[0]).toMatchObject({
      verified: true,
      message: 'Firma verificada localmente contra la revisión descargada.',
      checks: {
        challengeMatches: true,
        clientDataChallenge: true,
        clientDataOrigin: true,
        clientDataType: true,
        cryptographicSignature: true,
        curpBinding: true,
        curpFormat: true,
        documentHashMatches: true,
        intentCanonical: true,
        revisionMatches: true,
        rpIdHash: true,
        userPresent: true,
      },
    })
  })

  it('falla cuando la CURP mostrada no coincide con la intención firmada', async () => {
    const { bundle, fileBytes } = await createBundleFixture()
    bundle.signatures[0].signedBy.curp = 'PELJ900101HDFRNS01'

    const report = await verifyDocumentBundleLocally(bundle, fileBytes.buffer)

    expect(report.verified).toBe(false)
    expect(report.signatures[0].checks.curpFormat).toBe(true)
    expect(report.signatures[0].checks.curpBinding).toBe(false)
  })

  it('mantiene la CURP ausente como estado informativo', async () => {
    const { bundle, fileBytes } = await createBundleFixture(null)

    const report = await verifyDocumentBundleLocally(bundle, fileBytes.buffer)

    expect(report.verified).toBe(true)
    expect(report.signatures[0].curpStatus).toBe('not_provided')
    expect(report.signatures[0].checks.curpBinding).toBeNull()
    expect(report.signatures[0].checks.curpFormat).toBeNull()
  })

  it('mantiene verificables las firmas heredadas sin vinculación CURP', async () => {
    const { bundle, fileBytes } = await createBundleFixture('legacy')

    const report = await verifyDocumentBundleLocally(bundle, fileBytes.buffer)

    expect(report.verified).toBe(true)
    expect(report.signatures[0].curpStatus).toBe('legacy')
  })

  it('falla la verificación local cuando el archivo descargado ya no coincide con la revisión firmada', async () => {
    const { bundle } = await createBundleFixture()
    const tamperedFileBytes = encoder.encode(
      'Carga útil alterada de la revisión de Casa Monarca.',
    )

    const report = await verifyDocumentBundleLocally(
      bundle,
      tamperedFileBytes.buffer,
    )

    expect(report.verified).toBe(false)
    expect(report.signatures).toHaveLength(1)
    expect(report.signatures[0].verified).toBe(false)
    expect(report.signatures[0].checks.documentHashMatches).toBe(false)
    expect(report.signatures[0].checks.cryptographicSignature).toBe(true)
    expect(report.signatures[0].message).toBe(
      'Falló una o más verificaciones locales.',
    )
  })
})
