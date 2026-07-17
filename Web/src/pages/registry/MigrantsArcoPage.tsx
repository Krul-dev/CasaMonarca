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

export function MigrantsArcoPage({ onSessionExpired, user }: Props) {
  const [entries, setEntries] = useState<RegistryEntry[]>([]); const [requests, setRequests] = useState<ArcoRequest[]>([])
  const [loading, setLoading] = useState(true); const [error, setError] = useState<string | null>(null); const [message, setMessage] = useState<string | null>(null); const [busyId, setBusyId] = useState<number | null>(null)
  const load = useCallback(async () => { setLoading(true); setError(null); try { const [entryResponse, requestResponse] = await Promise.all([getRegistryEntries(), getArcoRequests()]); setEntries(entryResponse.data); setRequests(requestResponse.data) } catch (caught) { if (caught instanceof ApiRequestError && caught.status === 401) { onSessionExpired?.(); return } setError(caught instanceof Error ? caught.message : 'Unable to load ARCO requests.') } finally { setLoading(false) } }, [onSessionExpired])
  useEffect(() => { void load() }, [load])
  const pending = useMemo(() => requests.filter((request) => request.status === 'pending_coordinator' || request.status === 'pending_admin'), [requests])
  const resolved = useMemo(() => requests.filter((request) => request.status === 'completed' || request.status === 'rejected'), [requests])

  const decide = async (arco: ArcoRequest, stage: 'coordinator' | 'admin', decision: ArcoDecision) => {
    const needsReason = decision === 'reject' || arco.request_type === 'opposition'; const reason = window.prompt(needsReason ? 'Resolution reason (required)' : 'Optional resolution note')?.trim() || undefined
    if (needsReason && !reason) return
    setBusyId(arco.id); setError(null); setMessage(null); let challengeId: string | null = null
    try { const options = await startArcoDecision(arco.id, stage, { decision, reason }); challengeId = options.challengeIntent.id; const assertion = await getWebauthnAssertion(options.options); const response = await verifyArcoDecision(arco.id, stage, assertion); setMessage(response.message); await load() }
    catch (caught) { if (challengeId && caught instanceof DOMException && caught.name === 'NotAllowedError') await cancelSecurityChallenge(challengeId); if (caught instanceof ApiRequestError && caught.status === 401) { onSessionExpired?.(); return } setError(caught instanceof Error ? caught.message : 'Unable to complete the ARCO decision.') }
    finally { setBusyId(null) }
  }

  return <section className="workspace-stack"><section className="workspace-panel"><div className="arco-page__heading"><div><h2 className="workspace-panel__title">ARCO rights workspace</h2><p className="workspace-panel__copy">Signed Access, Rectification, Cancellation, and Opposition requests.</p></div><button className="session-action session-action--quiet session-action--inline" onClick={() => void load()} type="button">Refresh</button></div>
    <ArcoRequestForm entries={entries} onCreated={load} onSessionExpired={onSessionExpired} user={user} />
    {message ? <div className="login-feedback login-feedback--success">{message}</div> : null}{error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
    <section className="arco-section"><h2>Pending action</h2>{loading ? <p>Loading requests...</p> : <ArcoRequestList busyId={busyId} onDecision={(request, stage, decision) => void decide(request, stage, decision)} onSessionExpired={onSessionExpired} requests={pending} user={user} />}</section>
    <section className="arco-section"><h2>Resolved requests</h2><ArcoRequestList busyId={busyId} onDecision={(request, stage, decision) => void decide(request, stage, decision)} onSessionExpired={onSessionExpired} requests={resolved} user={user} /></section>
  </section></section>
}
