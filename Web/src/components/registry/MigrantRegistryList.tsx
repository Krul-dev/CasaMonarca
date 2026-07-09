import type { RegistryEntry } from '../../lib/registry'

type Props = {
  entries: RegistryEntry[]
}

const formatName = (entry: RegistryEntry) =>
  String(entry.payload_json.fullName || entry.payload_json.full_name || `Registration #${entry.id}`)

export function MigrantRegistryList({ entries }: Props) {
  if (entries.length === 0) {
    return <p>No hay registros disponibles.</p>
  }

  return (
    <div className="registry-list">
      {entries.map((entry) => (
        <article key={entry.id} className="registry-list__item">
          <h3>{formatName(entry)}</h3>
          <p>Estado: {entry.current_status}</p>
          <p>Origen: {entry.payload_json.countryOfOrigin ? String(entry.payload_json.countryOfOrigin) : 'N/A'}</p>
          <p>Creado por: {entry.creator?.email ?? entry.created_by_role}</p>
          <p>Fecha: {new Date(entry.created_at).toLocaleString()}</p>
        </article>
      ))}
    </div>
  )
}
