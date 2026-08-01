import { RoleBadge } from '../components/ui/RoleBadge'
import type { UserRole } from '../lib/auth'

type ForbiddenPageProps = {
  onNavigate: (to: string) => void
  role: UserRole
}

export function ForbiddenPage({ onNavigate, role }: ForbiddenPageProps) {
  return (
    <main className="route-shell">
      <section className="route-card route-card--compact">
        <p className="route-kicker">Casa Monarca</p>
        <h1 className="route-title">Acceso denegado</h1>
        <p className="route-copy">
          Tu rol actual (<RoleBadge role={role} />) no tiene permiso para
          acceder a esta área.
        </p>
        <a
          className="route-link"
          href="/app"
          onClick={(event) => {
            event.preventDefault()
            onNavigate('/app')
          }}
        >
          Ir a /app
        </a>
      </section>
    </main>
  )
}
