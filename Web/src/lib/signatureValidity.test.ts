import { describe, expect, it } from 'vitest'

import { getSignatureValidityState } from './signatureValidity'

describe('getSignatureValidityState', () => {
  it('computes a future policy expiry and countdown', () => {
    const expiresAt = '2026-05-23T00:00:00Z'
    const nowMs = Date.parse('2026-04-24T00:00:00Z')

    const state = getSignatureValidityState(expiresAt, nowMs)

    expect(state.expiresAt).toBe('2026-05-23T00:00:00.000Z')
    expect(state.expired).toBe(false)
    expect(state.countdownLabel).toBe('29d 0h 0m 0s')
  })

  it('marks expired signatures', () => {
    const expiresAt = '2026-05-23T00:00:00Z'
    const nowMs = Date.parse('2026-05-25T00:00:00Z')

    const state = getSignatureValidityState(expiresAt, nowMs)

    expect(state.expiresAt).toBe('2026-05-23T00:00:00.000Z')
    expect(state.expired).toBe(true)
    expect(state.countdownLabel).toBe('Expired 2d 0h 0m 0s ago')
  })

  it('returns unavailable state for invalid timestamps', () => {
    const state = getSignatureValidityState('not-a-date', Date.now())

    expect(state.expiresAt).toBeNull()
    expect(state.expired).toBe(false)
    expect(state.countdownLabel).toBe('Not available')
  })
})
