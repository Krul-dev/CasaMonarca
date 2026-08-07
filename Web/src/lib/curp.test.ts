import { describe, expect, it } from 'vitest'

import { isValidCurp, normalizeCurp } from './curp'

describe('CURP validation', () => {
  it('normalizes whitespace and casing', () => {
    expect(normalizeCurp(' sabc560626mdflrn01 ')).toBe('SABC560626MDFLRN01')
  })

  it('validates structure, calendar date, and check digit', () => {
    expect(isValidCurp('SABC560626MDFLRN01')).toBe(true)
    expect(isValidCurp('SABC560626MDFLRN02')).toBe(false)
    expect(isValidCurp('SABC560231MDFLRN08')).toBe(false)
    expect(isValidCurp('SABC560626MXXLRN08')).toBe(false)
  })
})
