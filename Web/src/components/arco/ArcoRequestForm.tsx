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
  { label: 'Access', value: 'access' }, { label: 'Rectification', value: 'rectification' },
  { label: 'Cancellation', value: 'cancellation' }, { label: 'Opposition', value: 'opposition' },
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

  const submit = async (proposal?: MigrantRegistrationPayload) => {
    if (!registryEntryId || !reason.trim()) { setMessage('Select a registration and enter the reason for the request.'); return }
    if (!window.isSecureContext || !('PublicKeyCredential' in window) || isIpHostname(window.location.hostname)) { setMessage('ARCO signatures require a secure context on localhost or a domain name.'); return }
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
      setMessage(
        error instanceof ApiRequestError && error.status >= 500
          ? 'The server could not save the ARCO request. Check the API log and try again.'
          : fields[0] ?? (error instanceof Error ? error.message : 'Unable to submit the ARCO request.'),
      )
    } finally { setBusy(false) }
  }

  return (
    <section className="arco-create">
      <div className="arco-create__header"><div><h2>Start an ARCO request</h2><p>Requests are submitted to coordinator review after passkey confirmation.</p></div><AppIcon name="sign" /></div>
      <div className="arco-create__fields">
        <label>Registration<select disabled={busy} onChange={(event) => setRegistryEntryId(event.target.value)} value={registryEntryId}><option value="">Select an approved registration</option>{eligible.map((entry) => <option key={entry.id} value={entry.id}>{String(entry.payload_json.fullName || `Registration #${entry.id}`)}</option>)}</select></label>
        <label>Right<select disabled={busy} onChange={(event) => setRequestType(event.target.value as ArcoRequestType)} value={requestType}>{types.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}</select></label>
        <label className="arco-create__reason">Reason<textarea disabled={busy} maxLength={2000} onChange={(event) => setReason(event.target.value)} required value={reason} /></label>
      </div>
      {selected ? (
        <section className="arco-create__documents">
          <h3>Documents covered by this request</h3>
          <MigrantDocumentsPanel
            canDelete={false}
            canDownload={user.role === 'admin' || user.role === 'coordinator'}
            canView
            embedded
            entryId={selected.id}
            onSessionExpired={onSessionExpired}
          />
        </section>
      ) : null}
      {requestType === 'rectification' && selected ? (
        <div className="arco-create__rectification"><h3>Proposed corrected information</h3><MigrantRegistryForm documentsEnabled={false} initialPayload={selected.payload_json} onSubmit={submit} submitLabel={busy ? 'Signing request...' : 'Sign and submit rectification'} successMessage="Rectification request submitted." /></div>
      ) : (
        <button className="session-action" disabled={busy || !registryEntryId || !reason.trim()} onClick={() => void submit()} type="button"><AppIcon name="sign" />{busy ? 'Waiting for passkey...' : 'Sign and submit request'}</button>
      )}
      {message ? <div className="login-feedback">{message}</div> : null}
    </section>
  )
}
