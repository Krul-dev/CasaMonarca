import { useEffect, useState } from 'react'

import { appName, apiBaseUrl } from '../config/env'
import { LoginForm } from '../components/auth/LoginForm'
import { getApiHealth } from '../lib/api'

type HealthState = {
  status: 'checking' | 'online' | 'offline'
  message: string
}

export function LoginPage() {
  const [healthState, setHealthState] = useState<HealthState>({
    status: 'checking',
    message: 'Validando conexion con el backend...',
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
          message: `API disponible: ${payload.service}`,
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
      <section className="login-layout">
        <div className="login-hero">
          <p className="route-kicker">CasaMonarca</p>
          <h1 className="route-title">Acceso listo para conectar autenticacion</h1>
          <p className="route-copy">
            Esta pantalla deja preparado el flujo visual, el contrato tipado y
            los estados principales para que el equipo conecte el login real sin
            empezar desde cero.
          </p>

          <div className="login-summary">
            <article className="summary-card">
              <span className="summary-card__label">Ruta lista</span>
              <strong>/login</strong>
              <p>La app ya tiene un punto de entrada real para autenticacion.</p>
            </article>

            <article className="summary-card">
              <span className="summary-card__label">API base</span>
              <strong>{apiBaseUrl}</strong>
              <p>La interfaz ya refleja el contrato esperado para el backend.</p>
            </article>

            <article className="summary-card">
              <span className="summary-card__label">Cliente</span>
              <strong>{appName}</strong>
              <p>La experiencia local y la de staging ahora usan la misma vista.</p>
            </article>
          </div>

          <div className="route-panel">
            <h2 className="route-panel__title">Pendiente para el equipo</h2>
            <ul className="route-checklist">
              <li>Conectar POST /login en el backend Laravel</li>
              <li>Persistir sesion o token segun la estrategia final</li>
              <li>Definir redireccion y guardas por rol</li>
            </ul>
          </div>
        </div>

        <section className="login-panel" aria-labelledby="login-panel-title">
          <div className="login-panel__header">
            <p className="login-panel__eyebrow">Inicio de sesion</p>
            <h2 className="login-panel__title" id="login-panel-title">
              Entrar al sistema
            </h2>
            <p className="login-panel__copy">
              Formulario listo para integrar autenticacion real y manejo de
              errores del backend.
            </p>
          </div>

          <LoginForm />

          <div className={`route-status route-status--${healthState.status}`}>
            {healthState.message}
          </div>
        </section>
      </section>
    </main>
  )
}
