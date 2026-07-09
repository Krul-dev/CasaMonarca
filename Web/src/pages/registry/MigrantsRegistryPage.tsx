import { useEffect, useState } from 'react'
import {
  ApiRequestError,
  createRegistryEntry,
  getRegistryEntries,
  type MigrantRegistrationPayload,
  type RegistryEntry,
} from '../../lib/registry'
import { MigrantRegistryForm } from '../../components/registry/MigrantRegistryForm'
import { MigrantRegistryList } from '../../components/registry/MigrantRegistryList'

type MigrantsRegistryPageProps = {
  onSessionExpired?: () => void
}

export function MigrantsRegistryPage({ onSessionExpired }: MigrantsRegistryPageProps) {
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

      setError(err instanceof Error? err.message: 'No fue posible cargar el registro.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadEntries()
  }, [])

  const handleCreate = async (payload_json: MigrantRegistrationPayload) => {
    await createRegistryEntry({ payload_json })
    await loadEntries()
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">Registration intake</h2>
        <p className="workspace-panel__copy">
          New submissions enter coordinator/admin review before becoming approved records.
        </p>

        <MigrantRegistryForm onSubmit={handleCreate} />
      </section>

      <section className="workspace-panel">
        <h2 className="workspace-panel__title">Recent registrations</h2>
        {loading? <p>Cargando registros...</p>: null}
        {error? <p className="route-error">{error}</p>: null}

        <MigrantRegistryList entries={entries} />
      </section>
    </section>
  )
}
