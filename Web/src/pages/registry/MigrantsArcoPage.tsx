import { useCallback, useEffect, useMemo, useState } from 'react'
import { ArcoRequestForm } from '../../components/arco/ArcoRequestForm'
import { ArcoRequestList } from '../../components/arco/ArcoRequestList'
import type { AuthenticatedUser } from '../../lib/auth'
import { getArcoRequests, startArcoDecision, verifyArcoDecision } from '../../lib/arco'
import { ApiRequestError, getRegistryEntries, type RegistryEntry } from '../../lib/registry'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion } from '../../lib/webauthn'
import type { ArcoDecision, ArcoRequest } from '../../types/arco'

type Props = { onSessionExpired?: () => void; user: AuthenticatedUser }
type PendingDecision = { decision: ArcoDecision; request: ArcoRequest; stage: 'coordinator' | 'admin' }

export function MigrantsArcoPage({ onSessionExpired, user }: Props) {
  const [entries, setEntries] = useState<RegistryEntry[]>([]); const [requests, setRequests] = useState<ArcoRequest[]>([])
  const [loading, setLoading] = useState(true); const [error, setError] = useState<string | null>(null); const [message, setMessage] = useState<string | null>(null); const [busyId, setBusyId] = useState<number | null>(null)
  const [pendingDecision, setPendingDecision] = useState<PendingDecision | null>(null)
  const [decisionReason, setDecisionReason] = useState('')
  const load = useCallback(async () => { setLoading(true); setError(null); try { const [entryResponse, requestResponse] = await Promise.all([getRegistryEntries(), getArcoRequests()]); setEntries(entryResponse.data); setRequests(requestResponse.data) } catch (caught) { if (caught instanceof ApiRequestError && caught.status === 401) { onSessionExpired?.(); return } setError(caught instanceof Error ? caught.message : 'Unable to load ARCO requests.') } finally { setLoading(false) } }, [onSessionExpired])
  useEffect(() => { void load() }, [load])
  const pending = useMemo(() => requests.filter((request) => request.status === 'pending_coordinator' || request.status === 'pending_admin'), [requests])
  const resolved = useMemo(() => requests.filter((request) => request.status === 'completed' || request.status === 'rejected'), [requests])

  const requestDecision = (request: ArcoRequest, stage: 'coordinator' | 'admin', decision: ArcoDecision) => {
    setPendingDecision({ decision, request, stage })
    setDecisionReason('')
    setError(null)
    setMessage(null)
  }

  const decide = async () => {
    if (!pendingDecision) return
    const { decision, request, stage } = pendingDecision
    const needsReason = decision === 'reject' || request.request_type === 'opposition'
    const reason = decisionReason.trim() || undefined
    if (needsReason && !reason) return
    setBusyId(request.id); setError(null); setMessage(null); let challengeId: string | null = null
    try { const options = await startArcoDecision(request.id, stage, { decision, reason }); challengeId = options.challengeIntent.id; const assertion = await getWebauthnAssertion(options.options); const response = await verifyArcoDecision(request.id, stage, assertion); setMessage(response.message); setPendingDecision(null); setDecisionReason(''); await load() }
    catch (caught) { if (challengeId && caught instanceof DOMException && caught.name === 'NotAllowedError') await cancelSecurityChallenge(challengeId); if (caught instanceof ApiRequestError && caught.status === 401) { onSessionExpired?.(); return } setError(caught instanceof Error ? caught.message : 'Unable to complete the ARCO decision.') }
    finally { setBusyId(null) }
  }

  const decisionNeedsReason = pendingDecision?.decision === 'reject' || pendingDecision?.request.request_type === 'opposition'

  return <section className="workspace-stack"><section className="workspace-panel"><div className="arco-page__heading"><div><h2 className="workspace-panel__title">ARCO rights workspace</h2><p className="workspace-panel__copy">Signed Access, Rectification, Cancellation, and Opposition requests.</p></div><button className="session-action session-action--quiet session-action--inline" onClick={() => void load()} type="button">Refresh</button></div>
    <ArcoRequestForm entries={entries} onCreated={load} onSessionExpired={onSessionExpired} user={user} />
    {message ? <div className="login-feedback login-feedback--success">{message}</div> : null}{error && !pendingDecision ? <div className="login-feedback login-feedback--error">{error}</div> : null}
    <section className="arco-section"><h2>Pending action</h2>{loading ? <p>Loading requests...</p> : <ArcoRequestList busyId={busyId} onDecision={requestDecision} onSessionExpired={onSessionExpired} requests={pending} user={user} />}</section>
    <section className="arco-section"><h2>Resolved requests</h2><ArcoRequestList busyId={busyId} onDecision={requestDecision} onSessionExpired={onSessionExpired} requests={resolved} user={user} /></section>
    {pendingDecision ? <div aria-labelledby="arco-decision-title" aria-modal="true" className="confirmation-modal" onClick={(event) => { if (event.target === event.currentTarget && busyId === null) setPendingDecision(null) }} onKeyDown={(event) => { if (event.key === 'Escape' && busyId === null) setPendingDecision(null) }} role="dialog">
      <div className="confirmation-modal__surface">
        <div><h3 id="arco-decision-title">{pendingDecision.decision === 'reject' ? 'Reject ARCO request' : 'Approve ARCO request'}</h3><p>Request #{pendingDecision.request.id} for {String(pendingDecision.request.registry_entry?.payload_json?.fullName || pendingDecision.request.original_payload_json?.fullName || `registration #${pendingDecision.request.registry_entry_id}`)}. This decision requires passkey authentication.</p></div>
        <label className="arco-decision-modal__reason">Resolution reason{decisionNeedsReason ? ' (required)' : ' (optional)'}<textarea autoFocus disabled={busyId !== null} maxLength={2000} onChange={(event) => setDecisionReason(event.target.value)} required={decisionNeedsReason} value={decisionReason} /></label>
        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
        <div className="confirmation-modal__actions"><button className="session-action session-action--quiet" disabled={busyId !== null} onClick={() => { setPendingDecision(null); setDecisionReason(''); setError(null) }} type="button">Cancel</button><button className="session-action" disabled={busyId !== null || (decisionNeedsReason && !decisionReason.trim())} onClick={() => void decide()} type="button">{busyId !== null ? 'Waiting for passkey...' : pendingDecision.decision === 'reject' ? 'Reject with passkey' : 'Approve with passkey'}</button></div>
      </div>
    </div> : null}
  </section></section>
}
