import type { RegistryEntry } from '../../lib/registry'

type Props = {
  entries: RegistryEntry[]
}

export function MigrantRegistryList({ entries }: Props) {
  if (entries.length === 0) {
    return <p>No hay registros disponibles.</p>
  }

  return (
    <div className="registry-list">
      {entries.map((entry) => (
        <article key={entry.id} className="registry-list__item">
          <h3>Registro #{entry.id}</h3>
          <p>Estado: {entry.current_status}</p>
          <p>Creado por: {entry.created_by_role}</p>
          <p>Fecha: {new Date(entry.created_at).toLocaleString()}</p>
        </article>
      ))}
    </div>
  )
}
