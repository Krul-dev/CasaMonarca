import { translate as t } from '../lib/i18n'
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
        <p className="route-kicker">{t("CasaMonarca", "Casa Monarca")}</p>
        <h1 className="route-title">{t("Access forbidden", "Acceso denegado")}</h1>
        <p className="route-copy">
          {t("Your current role (", "Tu rol actual (")}<RoleBadge role={role} />{t(") does not have permission to access this area. ", ") no tiene permiso para acceder a esta área. ")}</p>
        <a
          className="route-link"
          href="/app"
          onClick={(event) => {
            event.preventDefault()
            onNavigate('/app')
          }}
        >
          {t("Go to /app ", "Ir a /app ")}</a>
      </section>
    </main>
  )
}
