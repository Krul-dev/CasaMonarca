import { translate as t } from '../lib/i18n'
import { useEffect, useState } from 'react'

import { LoginForm } from '../components/auth/LoginForm'
import { getApiHealth } from '../lib/api'
import type { AuthenticatedUser } from '../lib/auth'

type HealthState = {
  status: 'checking' | 'online' | 'offline'
  message: string
}

type SessionNotice = {
  message: string
  tone: 'online' | 'offline'
}

type LoginPageProps = {
  initialEmail?: string | null
  onAuthenticated?: (user: AuthenticatedUser) => void
  sessionNotice?: SessionNotice | null
}

export function LoginPage({
  initialEmail = null,
  onAuthenticated,
  sessionNotice = null,
}: LoginPageProps) {
  const [healthState, setHealthState] = useState<HealthState>({
    status: 'checking',
    message: t("Checking backend availability...", "Verificando disponibilidad del backend..."),
  })

  useEffect(() => {
    let isMounted = true

    getApiHealth()
      .then((payload) => {
        if (!isMounted) {
          return
        }

        setHealthState({
          status: 'online',
          message: `API available: ${payload.service}`,
        })
      })
      .catch((error: Error) => {
        if (!isMounted) {
          return
        }

        setHealthState({
          status: 'offline',
          message: error.message,
        })
      })

    return () => {
      isMounted = false
    }
  }, [])

  return (
    <main className="route-shell">
      <section className="login-layout login-layout--single">
        <section className="login-panel" aria-labelledby="login-panel-title">
          <div className="login-panel__header">
            <p className="login-panel__eyebrow">{t("Sign in", "Inicio de sesión")}</p>
            <h2 className="login-panel__title" id="login-panel-title">
              {t("Sign in to the system ", "Inicia sesión en el sistema ")}</h2>
          </div>

          <LoginForm
            initialEmail={initialEmail ?? ''}
            onAuthenticated={onAuthenticated}
          />

          {sessionNotice ? (
            <div className={`route-status route-status--${sessionNotice.tone}`}>
              {sessionNotice.message}
            </div>
          ) : null}

          <div className={`route-status route-status--${healthState.status}`}>
            {healthState.message}
          </div>
        </section>
      </section>
    </main>
  )
}
