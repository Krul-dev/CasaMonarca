import { useCallback, useEffect, useState } from 'react'

import { AppIcon } from '../../components/ui/AppIcon'
import type { AuthenticatedUser } from '../../lib/auth'
import {
  ApiRequestError,
  getPendingRegistryApprovals,
  getPendingRegistryReviews,
  returnRegistryForCorrections,
  startRegistryApproval,
  startRegistryReview,
  type RegistryApprovalDecision,
  type RegistryEntry,
  verifyRegistryApproval,
  verifyRegistryReview,
} from '../../lib/registry'
import { cancelSecurityChallenge } from '../../lib/securityChallenges'
import { getWebauthnAssertion, isIpHostname } from '../../lib/webauthn'

type MigrantsApprovalsPageProps = {
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type QueueAction = 'approve' | 'forward' | 'reject' | 'return'

type ActionState = {
  entryId: number
  action: QueueAction
} | null

const canApprove = (role: AuthenticatedUser['role']) =>
  role === 'admin' || role === 'coordinator'

const formatEntryName = (entry: RegistryEntry) =>
  String(entry.payload_json.fullName || entry.payload_json.full_name || `Registration #${entry.id}`)

const formatEntrySubtitle = (entry: RegistryEntry) => {
  const payload = entry.pending_action === 'update' && entry.pending_payload_json
    ? entry.pending_payload_json
    : entry.payload_json
  const country = payload.countryOfOrigin ? String(payload.countryOfOrigin) : 'Country unavailable'
  const group = payload.populationGroup ? String(payload.populationGroup) : 'Population group unavailable'

  return `${country} · ${group}`
}

const ensurePasskeySupport = () => {
  if (!window.isSecureContext || !('PublicKeyCredential' in window)) {
    return 'A passkey action requires a secure context and supported browser.'
  }

  if (isIpHostname(window.location.hostname)) {
    return 'Passkey actions require localhost or a domain name, not an IP address.'
  }

  return null
}

export function MigrantsApprovalsPage({ onSessionExpired, user }: MigrantsApprovalsPageProps) {
  const [reviewEntries, setReviewEntries] = useState<RegistryEntry[]>([])
  const [approvalEntries, setApprovalEntries] = useState<RegistryEntry[]>([])
  const [isReviewsLoading, setIsReviewsLoading] = useState(true)
  const [isApprovalsLoading, setIsApprovalsLoading] = useState(canApprove(user.role))
  const [reviewError, setReviewError] = useState<string | null>(null)
  const [approvalError, setApprovalError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)
  const [actionState, setActionState] = useState<ActionState>(null)

  const loadReviews = useCallback(async () => {
    setIsReviewsLoading(true)
    setReviewError(null)

    try {
      const response = await getPendingRegistryReviews()
      setReviewEntries(response.data)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setReviewError(error instanceof Error ? error.message : 'Unable to load pending migrant reviews.')
    } finally {
      setIsReviewsLoading(false)
    }
  }, [onSessionExpired])

  const loadApprovals = useCallback(async () => {
    if (!canApprove(user.role)) {
      setApprovalEntries([])
      setIsApprovalsLoading(false)
      return
    }

    setIsApprovalsLoading(true)
    setApprovalError(null)

    try {
      const response = await getPendingRegistryApprovals()
      setApprovalEntries(response.data)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setApprovalError(error instanceof Error ? error.message : 'Unable to load pending migrant approvals.')
    } finally {
      setIsApprovalsLoading(false)
    }
  }, [onSessionExpired, user.role])

  const refreshQueues = useCallback(async () => {
    await Promise.all([loadReviews(), loadApprovals()])
  }, [loadApprovals, loadReviews])

  useEffect(() => {
    void refreshQueues()
  }, [refreshQueues])

  const handleReviewForward = async (entry: RegistryEntry) => {
    const supportError = ensurePasskeySupport()

    if (supportError) {
      setReviewError(supportError)
      return
    }

    setActionState({ entryId: entry.id, action: 'forward' })
    setReviewError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const reason = window.prompt('Optional review note')?.trim() || undefined
      const optionsResponse = await startRegistryReview(entry.id, { reason })
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyRegistryReview(entry.id, assertion)

      setMessage(response.message)
      await refreshQueues()
    } catch (error) {
      if (challengeIntentId && error instanceof DOMException && error.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setReviewError(
        error instanceof Error && error.name === 'NotAllowedError'
          ? 'Passkey review was cancelled.'
          : error instanceof Error
            ? error.message
            : 'Unable to forward the migrant registration for approval.',
      )
    } finally {
      setActionState(null)
    }
  }

  const handleReviewReturn = async (entry: RegistryEntry) => {
    const reason = window.prompt('Required correction notes')?.trim()

    if (!reason) {
      return
    }

    setActionState({ entryId: entry.id, action: 'return' })
    setReviewError(null)
    setMessage(null)

    try {
      const response = await returnRegistryForCorrections(entry.id, reason)
      setMessage(response.message)
      await refreshQueues()
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setReviewError(error instanceof Error ? error.message : 'Unable to return the registration for corrections.')
    } finally {
      setActionState(null)
    }
  }

  const handleApprovalDecision = async (entry: RegistryEntry, decision: RegistryApprovalDecision) => {
    const supportError = ensurePasskeySupport()

    if (supportError) {
      setApprovalError(supportError)
      return
    }

    const reason = decision === 'reject' ? window.prompt('Rejection reason')?.trim() : undefined

    if (decision === 'reject' && !reason) {
      return
    }

    setActionState({ entryId: entry.id, action: decision })
    setApprovalError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startRegistryApproval(entry.id, { decision, reason })
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyRegistryApproval(entry.id, assertion)

      setMessage(response.message)
      await refreshQueues()
    } catch (error) {
      if (challengeIntentId && error instanceof DOMException && error.name === 'NotAllowedError') {
        await cancelSecurityChallenge(challengeIntentId)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setApprovalError(
        error instanceof Error && error.name === 'NotAllowedError'
          ? 'Passkey approval was cancelled.'
          : error instanceof Error
            ? error.message
            : 'Unable to complete the approval decision.',
      )
    } finally {
      setActionState(null)
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel dashboard-signature-queue">
        <div className="dashboard-signature-queue__header">
          <div>
            <h2 className="workspace-panel__title">Registrations pending review</h2>
            <p className="workspace-panel__copy">
              Forward reviewed registrations with your passkey, or return them to the original submitter for correction.
            </p>
          </div>
          <button className="session-action session-action--quiet" disabled={isReviewsLoading} onClick={() => void refreshQueues()} type="button">
            <AppIcon name="refresh" />
            {isReviewsLoading ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>

        {reviewError ? <div className="login-feedback login-feedback--error">{reviewError}</div> : null}
        {message ? <div className="login-feedback login-feedback--success">{message}</div> : null}
        {!isReviewsLoading && reviewEntries.length === 0 && !reviewError ? (
          <p className="workspace-panel__copy">There are no migrant registrations pending review.</p>
        ) : null}

        <div className="signature-queue-list">
          {reviewEntries.map((entry) => {
            const isBusy = actionState?.entryId === entry.id

            return (
              <article className="signature-queue-card registry-approval-card" key={entry.id}>
                <div>
                  <strong>{formatEntryName(entry)}</strong>
                  <span>{formatEntrySubtitle(entry)}</span>
                  <small>Submitted {new Date(entry.created_at).toLocaleString()} by {entry.creator?.email ?? entry.created_by_role}</small>
                </div>
                <div className="registry-approval-card__actions">
                  <button className="session-action session-action--quiet" disabled={isBusy} onClick={() => void handleReviewReturn(entry)} type="button">
                    <AppIcon name="document" />
                    {isBusy && actionState?.action === 'return' ? 'Returning...' : 'Request corrections'}
                  </button>
                  <button className="session-action" disabled={isBusy} onClick={() => void handleReviewForward(entry)} type="button">
                    <AppIcon name="verify" />
                    {isBusy && actionState?.action === 'forward' ? 'Forwarding...' : 'Forward to approval'}
                  </button>
                </div>
              </article>
            )
          })}
        </div>
      </section>

      {canApprove(user.role) ? (
        <section className="workspace-panel dashboard-signature-queue">
          <div className="dashboard-signature-queue__header">
            <div>
              <h2 className="workspace-panel__title">Registrations pending final approval</h2>
              <p className="workspace-panel__copy">Coordinator/admin decisions require the reviewer passkey.</p>
            </div>
            <button className="session-action session-action--quiet" disabled={isApprovalsLoading} onClick={() => void refreshQueues()} type="button">
              <AppIcon name="refresh" />
              {isApprovalsLoading ? 'Refreshing...' : 'Refresh'}
            </button>
          </div>

          {approvalError ? <div className="login-feedback login-feedback--error">{approvalError}</div> : null}
          {!isApprovalsLoading && approvalEntries.length === 0 && !approvalError ? (
            <p className="workspace-panel__copy">There are no migrant registrations pending final approval.</p>
          ) : null}

          <div className="signature-queue-list">
            {approvalEntries.map((entry) => {
              const isBusy = actionState?.entryId === entry.id

              return (
                <article className="signature-queue-card registry-approval-card" key={entry.id}>
                  <div>
                    <strong>{formatEntryName(entry)}</strong>
                    <span>{formatEntrySubtitle(entry)}</span>
                    <small>Submitted {new Date(entry.created_at).toLocaleString()} by {entry.creator?.email ?? entry.created_by_role}</small>
                  </div>
                  <div className="registry-approval-card__actions">
                    <button className="session-action session-action--quiet" disabled={isBusy} onClick={() => void handleApprovalDecision(entry, 'reject')} type="button">
                      <AppIcon name="delete" />
                      {isBusy && actionState?.action === 'reject' ? 'Rejecting...' : 'Reject'}
                    </button>
                    <button className="session-action" disabled={isBusy} onClick={() => void handleApprovalDecision(entry, 'approve')} type="button">
                      <AppIcon name="verify" />
                      {isBusy && actionState?.action === 'approve' ? 'Approving...' : 'Approve'}
                    </button>
                  </div>
                </article>
              )
            })}
          </div>
        </section>
      ) : null}
    </section>
  )
}
