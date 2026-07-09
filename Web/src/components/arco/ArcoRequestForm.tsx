import { useState } from 'react'
import type { RegistryEntry } from '../../lib/registry'

type Props = {
  entries: RegistryEntry[]
}

export function ArcoRequestForm({ entries }: Props) {
  const [registryEntryId, setRegistryEntryId] = useState('')
  const [requestType, setRequestType] = useState('access')
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState<string | null>(null)

  const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setMessage(
      `Solicitud ARCO preparada para el registro ${registryEntryId} con tipo ${requestType}.`,
    )
  }

  return (
    <form className="arco-form" onSubmit={handleSubmit}>
      <label>
        Registro
        <select
          value={registryEntryId}
          onChange={(e) => setRegistryEntryId(e.target.value)}
        >
          <option value="">Selecciona un registro</option>
          {entries.map((entry) => (
            <option key={entry.id} value={entry.id}>
              Registro #{entry.id}
            </option>
          ))}
        </select>
      </label>

      <label>
        Tipo de solicitud
        <select value={requestType} onChange={(e) => setRequestType(e.target.value)}>
          <option value="access">Acceso</option>
          <option value="rectification">Rectificación</option>
          <option value="cancellation">Cancelación</option>
          <option value="opposition">Oposición</option>
        </select>
      </label>

      <label>
        Motivo
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} />
      </label>

      <button type="submit">Preparar solicitud ARCO</button>

      {message? <p>{message}</p>: null}
    </form>
  )
}
