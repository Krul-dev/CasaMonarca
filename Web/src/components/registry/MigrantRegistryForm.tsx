import { useState } from 'react'

type Props = {
  onSubmit: (payload_json: Record<string, unknown>) => Promise<void>
}

export function MigrantRegistryForm({ onSubmit }: Props) {
  const [fullName, setFullName] = useState('')
  const [nationality, setNationality] = useState('')
  const [notes, setNotes] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [message, setMessage] = useState<string | null>(null)

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSubmitting(true)
    setMessage(null)

    try {
      await onSubmit({
        full_name: fullName,
        nationality,
        notes,
      })

      setFullName('')
      setNationality('')
      setNotes('')
      setMessage('Registro enviado correctamente.')
    } catch (error) {
      setMessage(error instanceof Error? error.message: 'No fue posible enviar el registro.')
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form className="registry-form" onSubmit={handleSubmit}>
      <label>
        Nombre completo
        <input value={fullName} onChange={(e) => setFullName(e.target.value)} />
      </label>

      <label>
        Nacionalidad
        <input value={nationality} onChange={(e) => setNationality(e.target.value)} />
      </label>

      <label>
        Observaciones
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} />
      </label>

      <button type="submit" disabled={submitting}>
        {submitting? 'Enviando...': 'Guardar registro'}
      </button>

      {message? <p>{message}</p>: null}
    </form>
  )
}
