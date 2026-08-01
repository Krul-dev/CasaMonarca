import { describe, expect, it } from 'vitest'

import { getSignatureValidityState } from './signatureValidity'

describe('getSignatureValidityState', () => {
  it('calcula el vencimiento futuro de una política y su cuenta regresiva', () => {
    const expiresAt = '2026-05-23T00:00:00Z'
    const nowMs = Date.parse('2026-04-24T00:00:00Z')

    const state = getSignatureValidityState(expiresAt, nowMs)

    expect(state.expiresAt).toBe('2026-05-23T00:00:00.000Z')
    expect(state.expired).toBe(false)
    expect(state.countdownLabel).toBe('29d 0h 0m 0s')
  })

  it('marca las firmas vencidas', () => {
    const expiresAt = '2026-05-23T00:00:00Z'
    const nowMs = Date.parse('2026-05-25T00:00:00Z')

    const state = getSignatureValidityState(expiresAt, nowMs)

    expect(state.expiresAt).toBe('2026-05-23T00:00:00.000Z')
    expect(state.expired).toBe(true)
    expect(state.countdownLabel).toBe('Venció hace 2d 0h 0m 0s')
  })

  it('devuelve un estado no disponible para marcas de tiempo inválidas', () => {
    const state = getSignatureValidityState('not-a-date', Date.now())

    expect(state.expiresAt).toBeNull()
    expect(state.expired).toBe(false)
    expect(state.countdownLabel).toBe('No disponible')
  })
})
