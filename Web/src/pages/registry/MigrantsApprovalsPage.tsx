import { useCallback, useEffect, useState } from 'react'
import { AppIcon } from '../../components/ui/AppIcon'
import type { AuthenticatedUser } from '../../lib/auth'
import {
  ApiRequestError,
  getPendingRegistryApprovals,
  startRegistryApproval,
  verifyRegistryApproval,
  type RegistryApprovalDecision,
  type RegistryEntry,
} from '../../lib/registry'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion, isIpHostname } from '../../lib/webauthn'

type MigrantsApprovalsPageProps = {
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type ApprovalState = {
  entryId: number
  decision: RegistryApprovalDecision
} | null

const formatEntryName = (entry: RegistryEntry) =>
  String(entry.payload_json.fullName || entry.payload_json.full_name || `Registration #${entry.id}`)

const formatEntrySubtitle = (entry: RegistryEntry) => {
  const country = entry.payload_json.countryOfOrigin
    ? String(entry.payload_json.countryOfOrigin)
    : 'Country unavailable'
  const group = entry.payload_json.populationGroup
    ? String(entry.payload_json.populationGroup)
    : 'Population group unavailable'

  return `${country} · ${group}`
}

export function MigrantsApprovalsPage({
  onSessionExpired,
  user,
}: MigrantsApprovalsPageProps) {
  const [entries, setEntries] = useState<RegistryEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [approvalState, setApprovalState] = useState<ApprovalState>(null)

  const loadEntries = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await getPendingRegistryApprovals()
      setEntries(response.data)
    } catch (err) {
      if (err instanceof ApiRequestError && err.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(err instanceof Error ? err.message : 'Unable to load pending registrations.')
    } finally {
      setLoading(false)
    }
  }, [onSessionExpired])

  useEffect(() => {
    void loadEntries()
  }, [loadEntries])

  const handleDecision = async (
    entry: RegistryEntry,
    decision: RegistryApprovalDecision,
  ) => {
    if (!window.isSecureContext || !('PublicKeyCredential' in window)) {
      setError('Passkey approval requires a secure context and supported browser.')
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setError('Passkey approval requires localhost or a domain name, not an IP address.')
      return
    }

    const reason =
      decision === 'reject'
        ? window.prompt('Rejection reason')?.trim()
        : undefined

    if (decision === 'reject' && !reason) {
      return
    }

    setApprovalState({ entryId: entry.id, decision })
    setError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startRegistryApproval(entry.id, {
        decision,
        reason,
      })
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const verifyResponse = await verifyRegistryApproval(entry.id, assertion)

      setMessage(verifyResponse.message)
      await loadEntries()
    } catch (err) {
      if (challengeIntentId && err instanceof DOMException && err.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId)
      }

      if (err instanceof ApiRequestError && err.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(
        err instanceof Error
          ? err.name === 'NotAllowedError'
            ? 'Passkey approval was cancelled.'
            : err.message
          : 'Unable to complete the approval decision.',
      )
    } finally {
      setApprovalState(null)
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel dashboard-signature-queue">
        <div className="dashboard-signature-queue__header">
          <div>
            <h2 className="workspace-panel__title">Migrant registrations pending approval</h2>
            <p className="workspace-panel__copy">
              Coordinator/admin approval requires the reviewer passkey.
            </p>
          </div>
          <button
            className="session-action session-action--quiet"
            disabled={loading}
            onClick={() => void loadEntries()}
            type="button"
          >
            <AppIcon name="refresh" />
            {loading ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>

        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
        {message ? <div className="login-feedback login-feedback--success">{message}</div> : null}

        {!loading && entries.length === 0 && !error ? (
          <p className="workspace-panel__copy">There are no migrant registrations pending approval.</p>
        ) : null}

        <div className="signature-queue-list">
          {entries.map((entry) => {
            const isOwnCoordinatorSubmission =
              user.role === 'coordinator' && entry.created_by === user.id
            const isBusy = approvalState?.entryId === entry.id

            return (
              <article className="signature-queue-card registry-approval-card" key={entry.id}>
                <div>
                  <strong>{formatEntryName(entry)}</strong>
                  <span>{formatEntrySubtitle(entry)}</span>
                  <small>
                    Submitted {new Date(entry.created_at).toLocaleString()} by{' '}
                    {entry.creator?.email ?? entry.created_by_role}
                  </small>
                  {isOwnCoordinatorSubmission ? (
                    <small>Coordinators cannot approve their own submissions.</small>
                  ) : null}
                </div>
                <div className="registry-approval-card__actions">
                  <button
                    className="session-action session-action--quiet"
                    disabled={isBusy || isOwnCoordinatorSubmission}
                    onClick={() => void handleDecision(entry, 'reject')}
                    type="button"
                  >
                    <AppIcon name="delete" />
                    {isBusy && approvalState?.decision === 'reject' ? 'Rejecting...' : 'Reject'}
                  </button>
                  <button
                    className="session-action"
                    disabled={isBusy || isOwnCoordinatorSubmission}
                    onClick={() => void handleDecision(entry, 'approve')}
                    type="button"
                  >
                    <AppIcon name="verify" />
                    {isBusy && approvalState?.decision === 'approve' ? 'Approving...' : 'Approve'}
                  </button>
                </div>
              </article>
            )
          })}
        </div>
      </section>
    </section>
  )
}
