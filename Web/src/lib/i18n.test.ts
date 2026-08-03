import { afterEach, describe, expect, it } from 'vitest'

import {
  APP_LOCALE_STORAGE_KEY,
  getAppLocale,
  setAppLocale,
  translate,
} from './i18n'

afterEach(() => {
  setAppLocale('es')
  window.localStorage.clear()
})
describe('application locale', () => {
  it('defaults to Spanish', () => {
    expect(getAppLocale()).toBe('es')
    expect(translate('Documents', 'Documentos')).toBe('Documentos')
  })

  it('persists English and updates the document language', () => {
    setAppLocale('en')

    expect(getAppLocale()).toBe('en')
    expect(window.localStorage.getItem(APP_LOCALE_STORAGE_KEY)).toBe('en')
    expect(document.documentElement.lang).toBe('en')
    expect(translate('Documents', 'Documentos')).toBe('Documents')
  })
})
