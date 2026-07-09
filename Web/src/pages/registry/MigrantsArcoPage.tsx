import { useEffect, useState } from 'react'
import { ApiRequestError, getRegistryEntries, type RegistryEntry } from '../../lib/registry'
import { ArcoRequestForm } from '../../components/arco/ArcoRequestForm'
import { ArcoRequestList } from '../../components/arco/ArcoRequestList'

type MigrantsArcoPageProps = {
  onSessionExpired?: () => void
}

export function MigrantsArcoPage({ onSessionExpired }: MigrantsArcoPageProps) {
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
      if (err instanceof ApiRequestError && err.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(err instanceof Error? err.message: 'No fue posible cargar ARCO.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadEntries()
  }, [])

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">ARCO requests</h2>

        <ArcoRequestForm entries={entries} />

        {loading? <p>Cargando solicitudes...</p>: null}
        {error? <p className="route-error">{error}</p>: null}

        <ArcoRequestList />
      </section>
    </section>
  )
}
