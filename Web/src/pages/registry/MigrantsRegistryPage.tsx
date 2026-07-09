import { useEffect, useState } from 'react'
import {
  ApiRequestError,
  createRegistryEntry,
  deleteRegistryEntry,
  getRegistryEntries,
  type MigrantRegistrationPayload,
  type RegistryEntry,
  updateRegistryEntry,
} from '../../lib/registry'
import type { AuthenticatedUser } from '../../lib/auth'
import { MigrantRegistryForm } from '../../components/registry/MigrantRegistryForm'
import { MigrantRegistryList } from '../../components/registry/MigrantRegistryList'

type MigrantsRegistryPageProps = {
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

export function MigrantsRegistryPage({
  onSessionExpired,
  user,
}: MigrantsRegistryPageProps) {
  const [entries, setEntries] = useState<RegistryEntry[]>([])
  const [editingEntry, setEditingEntry] = useState<RegistryEntry | null>(null)
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

  const handleUpdate = async (payload_json: MigrantRegistrationPayload) => {
    if (!editingEntry) {
      return
    }

    await updateRegistryEntry(editingEntry.id, { payload_json })
    setEditingEntry(null)
    await loadEntries()
  }

  const handleDelete = async (entry: RegistryEntry) => {
    if (!window.confirm(`Delete ${entry.payload_json.fullName ?? `registration #${entry.id}`}?`)) {
      return
    }

    setError(null)

    try {
      await deleteRegistryEntry(entry.id)
      if (editingEntry?.id === entry.id) {
        setEditingEntry(null)
      }
      await loadEntries()
    } catch (err) {
      if (err instanceof ApiRequestError && err.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(err instanceof Error ? err.message : 'Unable to delete the registration.')
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">
          {editingEntry ? 'Modify registration' : 'Registration intake'}
        </h2>
        <p className="workspace-panel__copy">
          {editingEntry
            ? 'Submitted changes enter coordinator/admin review before replacing the approved record.'
            : 'New submissions enter coordinator/admin review before becoming approved records.'}
        </p>

        <MigrantRegistryForm
          initialPayload={editingEntry?.payload_json ?? null}
          onCancel={editingEntry ? () => setEditingEntry(null) : undefined}
          onSubmit={editingEntry ? handleUpdate : handleCreate}
          submitLabel={editingEntry ? 'Submit modification' : 'Submit registration'}
        />
      </section>

      <section className="workspace-panel">
        <h2 className="workspace-panel__title">Recent registrations</h2>
        {loading? <p>Cargando registros...</p>: null}
        {error? <p className="route-error">{error}</p>: null}

        <MigrantRegistryList
          canDelete={user.role === 'admin'}
          entries={entries}
          onDelete={user.role === 'admin' ? handleDelete : undefined}
          onEdit={setEditingEntry}
        />
      </section>
    </section>
  )
}
