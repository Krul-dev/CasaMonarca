import { useMemo, useState } from 'react'

import { startArcoRequest, verifyArcoRequest } from '../../lib/arco'
import { ApiRequestError, type MigrantRegistrationPayload, type RegistryEntry } from '../../lib/registry'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion, isIpHostname } from '../../lib/webauthn'
import type { ArcoRequestType } from '../../types/arco'
import { arcoEnabledTypes } from '../../config/env'
import type { AuthenticatedUser } from '../../lib/auth'
import { MigrantDocumentsPanel } from '../registry/MigrantDocumentsPanel'
import { MigrantRegistryForm } from '../registry/MigrantRegistryForm'
import { AppIcon } from '../ui/AppIcon'

type Props = { entries: RegistryEntry[]; onCreated: () => Promise<void>; onSessionExpired?: () => void; user: AuthenticatedUser }

const allTypes: Array<{ label: string; value: ArcoRequestType }> = [
  { label: 'Acceso', value: 'access' }, { label: 'Rectificación', value: 'rectification' },
  { label: 'Cancelación', value: 'cancellation' }, { label: 'Oposición', value: 'opposition' },
]
const types = allTypes.filter((type) => arcoEnabledTypes.includes(type.value))

export function ArcoRequestForm({ entries, onCreated, onSessionExpired, user }: Props) {
  const eligible = useMemo(() => entries.filter((entry) => entry.current_status === 'approved' && !entry.pending_action), [entries])
  const [registryEntryId, setRegistryEntryId] = useState('')
  const [requestType, setRequestType] = useState<ArcoRequestType>('access')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const selected = eligible.find((entry) => entry.id === Number(registryEntryId))

  const submit = async (proposal?: MigrantRegistrationPayload, propagateError = false) => {
    if (!registryEntryId || !reason.trim()) {
      const error = new Error('Selecciona un registro e ingresa el motivo de la solicitud.')
      if (propagateError) throw error
      setMessage(error.message)
      return
    }
    if (!window.isSecureContext || !('PublicKeyCredential' in window) || isIpHostname(window.location.hostname)) {
      const error = new Error('Las firmas ARCO requieren un contexto seguro en localhost o un nombre de dominio.')
      if (propagateError) throw error
      setMessage(error.message)
      return
    }
    setBusy(true); setMessage(null)
    let challengeId: string | null = null
    try {
      const options = await startArcoRequest({ registryEntryId: Number(registryEntryId), requestType, reason: reason.trim(), ...(proposal ? { proposedPayload: proposal } : {}) })
      challengeId = options.challengeIntent.id
      const assertion = await getWebauthnAssertion(options.options)
      const response = await verifyArcoRequest(assertion)
      setMessage(response.message); setReason(''); setRegistryEntryId(''); await onCreated()
    } catch (error) {
      if (challengeId && error instanceof DOMException && error.name === 'NotAllowedError') await cancelSecurityChallenge(challengeId)
      const fields = error instanceof ApiRequestError && error.errors ? Object.values(error.errors).flat() : []
      const failureMessage =
        error instanceof ApiRequestError && error.status >= 500
          ? 'El servidor no pudo guardar la solicitud ARCO. Revisa la bitácora de la API e inténtalo de nuevo.'
          : fields[0] ?? (error instanceof Error ? error.message : 'No se pudo enviar la solicitud ARCO.')
      if (propagateError) throw new Error(failureMessage)
      setMessage(failureMessage)
    } finally { setBusy(false) }
  }

  return (
    <section className="arco-create">
      <div className="arco-create__header"><div><h2>Iniciar una solicitud ARCO</h2><p>Las solicitudes se envían a revisión de coordinación después de confirmar con llave de acceso.</p></div><AppIcon name="sign" /></div>
      <div className="arco-create__fields">
        <label>Registro<select disabled={busy} onChange={(event) => setRegistryEntryId(event.target.value)} value={registryEntryId}><option value="">Selecciona un registro aprobado</option>{eligible.map((entry) => <option key={entry.id} value={entry.id}>{String(entry.payload_json.fullName || `Registro #${entry.id}`)}</option>)}</select></label>
        <label>Derecho<select disabled={busy} onChange={(event) => setRequestType(event.target.value as ArcoRequestType)} value={requestType}>{types.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></label>
        <label className="arco-create__reason">Motivo<textarea disabled={busy} maxLength={2000} onChange={(event) => setReason(event.target.value)} required value={reason} /></label>
      </div>
      {selected ? (
        <section className="arco-create__documents">
          <h3>Documentos cubiertos por esta solicitud</h3>
          <MigrantDocumentsPanel
            canDelete={false}
            canDownload={user.role === 'admin' || user.role === 'coordinator'}
            canDownloadArcoApproved={user.role === 'non_coordinator'}
            canView
            embedded
            entryId={selected.id}
            onSessionExpired={onSessionExpired}
          />
        </section>
      ) : null}
      {requestType === 'rectification' && selected ? (
        <div className="arco-create__rectification"><h3>Información corregida propuesta</h3><MigrantRegistryForm documentsEnabled={false} initialPayload={selected.payload_json} onSubmit={(proposal) => submit(proposal, true)} submitLabel={busy ? 'Firmando solicitud...' : 'Firmar y enviar rectificación'} successMessage="Solicitud de rectificación enviada." /></div>
      ) : (
        <button className="session-action" disabled={busy || !registryEntryId || !reason.trim()} onClick={() => void submit()} type="button"><AppIcon name="sign" />{busy ? 'Esperando llave de acceso...' : 'Firmar y enviar solicitud'}</button>
      )}
      {message ? <div className="login-feedback">{message}</div> : null}
    </section>
  )
}
