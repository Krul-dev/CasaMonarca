import { useEffect, useState } from 'react'
import {
  createRegistryEntry,
  getRegistryEntries,
  type RegistryEntry,
} from '../../lib/registry'
import { MigrantRegistryForm } from '../../components/registry/MigrantRegistryForm'
import { MigrantRegistryList } from '../../components/registry/MigrantRegistryList'

export function MigrantsRegistryPage() {
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
      setError(err instanceof Error? err.message: 'No fue posible cargar el registro.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadEntries()
  }, [])

  const handleCreate = async (payload_json: Record<string, unknown>) => {
    await createRegistryEntry({ payload_json })
    await loadEntries()
  }

  return (
    <main className="route-shell">
      <section className="route-card">
        <p className="route-kicker">Registro de migrantes</p>
        <h1 className="route-title">Alta y seguimiento</h1>

        <MigrantRegistryForm onSubmit={handleCreate} />

        {loading? <p>Cargando registros...</p>: null}
        {error? <p className="route-error">{error}</p>: null}

        <MigrantRegistryList entries={entries} />
      </section>
    </main>
  )
}
