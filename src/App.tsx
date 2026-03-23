import './App.css'
import { LoginPage } from './pages/LoginPage'

const normalizePathname = (pathname: string) => {
  if (pathname === '/') {
    return '/login'
  }

  return pathname.endsWith('/') && pathname !== '/'
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
          Esta vista todavia no existe en el cliente web. El punto de entrada
          actual es la pantalla de acceso.
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

  return <NotFoundPage />
}

export default App
