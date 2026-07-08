import './App.css'
import { LoginPage } from './pages/LoginPage'
import { MigrantsRegistryPage } from './pages/registry/MigrantsRegistryPage'
import { MigrantsArcoPage } from './pages/registry/MigrantsArcoPage'

const normalizePathname = (pathname: string) => {
  if (pathname === '/') {
    return '/login'
  }

  return pathname.endsWith('/') && pathname!== '/'
? pathname.slice(0, -1)
: pathname
}

function NotFoundPage() {
  return (
    <main className="route-shell">
      <section className="route-card route-card--compact">
        <p className="route-kicker">CasaMonarca</p>
        <h1 className="route-title">Ruta pendiente</h1>
        <p className="route-copy">
          Esta vista todavía no existe en el cliente web.
        </p>
        <a className="route-link" href="/login">
          Ir a /login
        </a>
      </section>
    </main>
  )
}

function App() {
  const pathname = normalizePathname(window.location.pathname)

  if (pathname === '/login') {
    return <LoginPage />
  }

  if (pathname === '/registry/migrants') {
    return <MigrantsRegistryPage />
  }

  if (pathname === '/registry/migrants/arco') {
    return <MigrantsArcoPage />
  }

  return <NotFoundPage />
}

export default App
