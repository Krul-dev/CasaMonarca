import { useEffect, useState } from 'react'
import { getRegistryEntries, type RegistryEntry } from '../../lib/registry'
import { ArcoRequestForm } from '../../components/arco/ArcoRequestForm'
import { ArcoRequestList } from '../../components/arco/ArcoRequestList'

export function MigrantsArcoPage() {
  const [entries, setEntries] = useState<RegistryEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  const loadEntries = async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await getRegistryEntries()
      setEntries(response.data)
    } catch (err) {
      setError(err instanceof Error? err.message: 'No fue posible cargar ARCO.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadEntries()
  }, [])

  return (
    <main className="route-shell">
      <section className="route-card">
        <p className="route-kicker">Derechos ARCO</p>
        <h1 className="route-title">Solicitudes y resolución</h1>

        <ArcoRequestForm entries={entries} />

        {loading? <p>Cargando solicitudes...</p>: null}
        {error? <p className="route-error">{error}</p>: null}

        <ArcoRequestList />
      </section>
    </main>
  )
}
