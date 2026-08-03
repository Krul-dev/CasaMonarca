import { useSyncExternalStore } from 'react'

export type AppLocale = 'en' | 'es'

export const APP_LOCALE_STORAGE_KEY = 'casamonarca.locale'

const DEFAULT_LOCALE: AppLocale = 'es'

const isAppLocale = (value: string | null): value is AppLocale =>
  value === 'en' || value === 'es'

const readStoredLocale = (): AppLocale => {
  if (typeof window === 'undefined') {
    return DEFAULT_LOCALE
  }

  try {
    const storedLocale = window.localStorage.getItem(APP_LOCALE_STORAGE_KEY)
    return isAppLocale(storedLocale) ? storedLocale : DEFAULT_LOCALE
  } catch {
    return DEFAULT_LOCALE
  }
}

let currentLocale = readStoredLocale()
const listeners = new Set<() => void>()

const updateDocumentLanguage = (locale: AppLocale) => {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = locale
  }
}

updateDocumentLanguage(currentLocale)

export const getAppLocale = () => currentLocale

export const setAppLocale = (locale: AppLocale) => {
  if (locale === currentLocale) {
    return
  }

  currentLocale = locale
  updateDocumentLanguage(locale)

  try {
    window.localStorage.setItem(APP_LOCALE_STORAGE_KEY, locale)
  } catch {
    // The preference remains active for this page when storage is unavailable.
  }

  listeners.forEach((listener) => listener())
}

export const translate = <T>(english: T, spanish: T): T =>
  currentLocale === 'en' ? english : spanish

export const useAppLocale = () =>
  useSyncExternalStore(
    (listener) => {
      listeners.add(listener)
      return () => listeners.delete(listener)
    },
    getAppLocale,
    () => DEFAULT_LOCALE,
  )
