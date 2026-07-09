import type { RegistryEntry } from '../../lib/registry'

type Props = {
  canDelete?: boolean
  entries: RegistryEntry[]
  onDelete?: (entry: RegistryEntry) => void
  onEdit?: (entry: RegistryEntry) => void
}

const formatName = (entry: RegistryEntry) =>
  String(entry.payload_json.fullName || entry.payload_json.full_name || `Registration #${entry.id}`)

const statusLabel = (entry: RegistryEntry) => {
  if (entry.current_status === 'pending_approval' && entry.pending_action === 'update') {
    return 'pending update approval'
  }

  if (entry.current_status === 'pending_approval' && entry.pending_action === 'create') {
    return 'pending creation approval'
  }

  return entry.current_status
}

export function MigrantRegistryList({
  canDelete = false,
  entries,
  onDelete,
  onEdit,
}: Props) {
  if (entries.length === 0) {
    return <p>No hay registros disponibles.</p>
  }

  return (
    <div className="registry-list">
      {entries.map((entry) => (
        <article key={entry.id} className="registry-list__item">
          <div>
            <h3>{formatName(entry)}</h3>
            <p>Estado: {statusLabel(entry)}</p>
            <p>Origen: {entry.payload_json.countryOfOrigin ? String(entry.payload_json.countryOfOrigin) : 'N/A'}</p>
            <p>Creado por: {entry.creator?.email ?? entry.created_by_role}</p>
            <p>Fecha: {new Date(entry.created_at).toLocaleString()}</p>
          </div>
          <div className="registry-list__actions">
            {onEdit ? (
              <button
                className="session-action session-action--quiet"
                disabled={entry.current_status === 'pending_approval'}
                onClick={() => onEdit(entry)}
                type="button"
              >
                Edit
              </button>
            ) : null}
            {canDelete && onDelete ? (
              <button
                className="session-action session-action--quiet"
                onClick={() => onDelete(entry)}
                type="button"
              >
                Delete
              </button>
            ) : null}
          </div>
        </article>
      ))}
    </div>
  )
}
