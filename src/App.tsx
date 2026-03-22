import { useEffect, useState } from 'react'

import './App.css'
import { apiBaseUrl } from './config/env'
import { getApiHealth } from './lib/api'

type HealthState = {
  status: 'checking' | 'online' | 'offline'
  message: string
}

function App() {
  const frontendOrigin = window.location.origin

  const [healthState, setHealthState] = useState<HealthState>({
    status: 'checking',
    message: 'Checking Laravel API connectivity...',
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
          message: `API online: ${payload.service}`,
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
    <main className="app-shell">
      <section className="app-card">
        <p className="app-kicker">CasaMonarca</p>
        <h1 className="app-title">Control de Acceso</h1>
        <dl className="app-meta">
          <div>
            <dt>Frontend</dt>
            <dd>{frontendOrigin}</dd>
          </div>
          <div>
            <dt>API Base</dt>
            <dd>{apiBaseUrl}</dd>
          </div>
        </dl>
        <div className={`app-status app-status--${healthState.status}`}>
          {healthState.message}
        </div>
      </section>
    </main>
  )
}

export default App
