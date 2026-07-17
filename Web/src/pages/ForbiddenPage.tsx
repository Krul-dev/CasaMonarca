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
        <p className="route-kicker">CasaMonarca</p>
        <h1 className="route-title">Access forbidden</h1>
        <p className="route-copy">
          Your current role (<RoleBadge role={role} />) does not have permission to
          access this area.
        </p>
        <a
          className="route-link"
          href="/app"
          onClick={(event) => {
            event.preventDefault()
            onNavigate('/app')
          }}
        >
          Go to /app
        </a>
      </section>
    </main>
  )
}
