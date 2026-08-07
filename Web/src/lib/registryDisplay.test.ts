import { afterEach, describe, expect, it } from 'vitest'

import { setAppLocale } from './i18n'
import { formatRegistryDate, formatRegistryValue } from './registryDisplay'

describe('registry display localization', () => {
  afterEach(() => setAppLocale('es'))

  it('translates stored registry values instead of exposing enum codes', () => {
    setAppLocale('es')
    expect(formatRegistryValue('adult')).toBe('Persona adulta (18-59 años)')
    expect(formatRegistryValue('coordinator')).toBe('Coordinación')
    expect(formatRegistryValue('pending_approval')).toBe('Pendiente de aprobación')

    setAppLocale('en')
    expect(formatRegistryValue('adult')).toBe('Adult (18-59 years)')
    expect(formatRegistryValue('coordinator')).toBe('Coordinator')
  })

  it('formats dates using the selected application language', () => {
    setAppLocale('es')
    expect(formatRegistryDate('2026-08-03T15:52:24Z', true)).toMatch(/2026/)
    expect(formatRegistryDate(null)).toBe('No disponible')

    setAppLocale('en')
    expect(formatRegistryDate(null)).toBe('Not available')
  })
})
