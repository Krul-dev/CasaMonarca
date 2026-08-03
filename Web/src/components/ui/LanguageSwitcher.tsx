import { setAppLocale, useAppLocale, type AppLocale } from '../../lib/i18n'

const LANGUAGE_OPTIONS: Array<{ label: string; locale: AppLocale }> = [
  { label: 'EN', locale: 'en' },
  { label: 'ES', locale: 'es' },
]

export function LanguageSwitcher() {
  const locale = useAppLocale()

  return (
    <aside
      aria-label={
        locale === 'en' ? 'Interface language' : 'Idioma de la interfaz'
      }
      className="language-switcher"
    >
      {LANGUAGE_OPTIONS.map((option) => (
        <button
          aria-pressed={locale === option.locale}
          className={locale === option.locale ? 'is-active' : ''}
          key={option.locale}
          onClick={() => {
            if (option.locale === locale) return
            setAppLocale(option.locale)
            window.location.reload()
          }}
          type="button"
        >
          {option.label}
        </button>
      ))}
    </aside>
  )
}
