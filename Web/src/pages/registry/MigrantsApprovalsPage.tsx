import { useCallback, useEffect, useMemo, useRef, useState } from 'react'

import { AppIcon } from '../../components/ui/AppIcon'
import { MigrantQuestionnaireViewer } from '../../components/registry/MigrantQuestionnaireViewer'
import type { AuthenticatedUser } from '../../lib/auth'
import {
  ApiRequestError,
  getPendingRegistryApprovals,
  getPendingRegistryReviews,
  returnRegistryForCorrections,
  startRegistryApproval,
  startRegistryBulkApproval,
  startRegistryReview,
  type RegistryApprovalDecision,
  type RegistryEntry,
  verifyRegistryApproval,
  verifyRegistryBulkApproval,
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

const getApprovalPayload = (entry: RegistryEntry) =>
  entry.pending_action === 'update' && entry.pending_payload_json
    ? entry.pending_payload_json
    : entry.payload_json

const formatEntryName = (entry: RegistryEntry) => {
  const payload = getApprovalPayload(entry)

  return String(payload.fullName || payload.full_name || `Registration #${entry.id}`)
}

const formatEntrySubtitle = (entry: RegistryEntry) => {
  const payload = getApprovalPayload(entry)
  const country = payload.countryOfOrigin ? String(payload.countryOfOrigin) : 'Country unavailable'
  const group = payload.populationGroup ? String(payload.populationGroup) : 'Population group unavailable'

  return `${country} · ${group}`
}

const getLocalDateValue = (dateValue: string) => {
  const date = new Date(dateValue)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const localDate = new Date(date.getTime() - date.getTimezoneOffset() * 60_000)

  return localDate.toISOString().slice(0, 10)
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
  const [approvalDateFrom, setApprovalDateFrom] = useState('')
  const [approvalDateTo, setApprovalDateTo] = useState('')
  const [approvalTypeFilter, setApprovalTypeFilter] = useState<'all' | 'create' | 'update'>('all')
  const [selectedApprovalIds, setSelectedApprovalIds] = useState<Set<number>>(() => new Set())
  const [isBulkApproving, setIsBulkApproving] = useState(false)
  const selectAllRef = useRef<HTMLInputElement>(null)

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

  const filteredApprovalEntries = useMemo(() => approvalEntries.filter((entry) => {
    const queuedDate = getLocalDateValue(entry.updated_at)

    return (
      (approvalTypeFilter === 'all' || entry.pending_action === approvalTypeFilter) &&
      (approvalDateFrom === '' || queuedDate >= approvalDateFrom) &&
      (approvalDateTo === '' || queuedDate <= approvalDateTo)
    )
  }), [approvalDateFrom, approvalDateTo, approvalEntries, approvalTypeFilter])
  const allFilteredApprovalsSelected = filteredApprovalEntries.length > 0 &&
    filteredApprovalEntries.every((entry) => selectedApprovalIds.has(entry.id))
  const someFilteredApprovalsSelected = filteredApprovalEntries.some((entry) =>
    selectedApprovalIds.has(entry.id),
  )

  useEffect(() => {
    if (selectAllRef.current) {
      selectAllRef.current.indeterminate = someFilteredApprovalsSelected && !allFilteredApprovalsSelected
    }
  }, [allFilteredApprovalsSelected, someFilteredApprovalsSelected])

  useEffect(() => {
    const availableIds = new Set(approvalEntries.map((entry) => entry.id))

    setSelectedApprovalIds((current) => {
      const next = new Set([...current].filter((entryId) => availableIds.has(entryId)))

      return next.size === current.size ? current : next
    })
  }, [approvalEntries])

  const clearApprovalFilters = () => {
    setApprovalDateFrom('')
    setApprovalDateTo('')
    setApprovalTypeFilter('all')
    setSelectedApprovalIds(new Set())
  }

  const toggleApprovalSelection = (entryId: number) => {
    setSelectedApprovalIds((current) => {
      const next = new Set(current)

      if (next.has(entryId)) {
        next.delete(entryId)
      } else {
        next.add(entryId)
      }

      return next
    })
  }

  const toggleAllFilteredApprovals = () => {
    setSelectedApprovalIds((current) => {
      const next = new Set(current)

      if (allFilteredApprovalsSelected) {
        filteredApprovalEntries.forEach((entry) => next.delete(entry.id))
      } else {
        filteredApprovalEntries.forEach((entry) => next.add(entry.id))
      }

      return next
    })
  }

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

  const handleBulkApproval = async () => {
    const supportError = ensurePasskeySupport()

    if (supportError) {
      setApprovalError(supportError)
      return
    }

    const entryIds = [...selectedApprovalIds].sort((left, right) => left - right)

    if (entryIds.length === 0 || !window.confirm(`Approve ${entryIds.length} selected registrations?`)) {
      return
    }

    setIsBulkApproving(true)
    setApprovalError(null)
    setMessage(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startRegistryBulkApproval(entryIds)
      challengeIntentId = optionsResponse.challengeIntent.id
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyRegistryBulkApproval(assertion)

      setMessage(response.message)
      setSelectedApprovalIds(new Set())
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
          ? 'Bulk passkey approval was cancelled.'
          : error instanceof Error
            ? error.message
            : 'Unable to approve the selected registrations.',
      )
    } finally {
      setIsBulkApproving(false)
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
                  <details className="registry-approval-card__details"><summary>Ver cuestionario completo</summary><MigrantQuestionnaireViewer payload={getApprovalPayload(entry)} /></details>
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

          <div aria-label="Final approval filters" className="audit-controls registry-approval-filters">
            <label className="audit-control">
              <span>Queued from</span>
              <input
                max={approvalDateTo || undefined}
                onChange={(event) => {
                  setApprovalDateFrom(event.target.value)
                  setSelectedApprovalIds(new Set())
                }}
                type="date"
                value={approvalDateFrom}
              />
            </label>

            <label className="audit-control">
              <span>Queued through</span>
              <input
                min={approvalDateFrom || undefined}
                onChange={(event) => {
                  setApprovalDateTo(event.target.value)
                  setSelectedApprovalIds(new Set())
                }}
                type="date"
                value={approvalDateTo}
              />
            </label>

            <label className="audit-control">
              <span>Request type</span>
              <select
                onChange={(event) => {
                  setApprovalTypeFilter(event.target.value as 'all' | 'create' | 'update')
                  setSelectedApprovalIds(new Set())
                }}
                value={approvalTypeFilter}
              >
                <option value="all">All requests</option>
                <option value="create">New registrations</option>
                <option value="update">Modifications</option>
              </select>
            </label>

            <button
              className="audit-controls__reset"
              disabled={approvalDateFrom === '' && approvalDateTo === '' && approvalTypeFilter === 'all'}
              onClick={clearApprovalFilters}
              type="button"
            >
              Clear filters
            </button>
          </div>

          <div className="registry-bulk-approval-toolbar">
            <label className="registry-bulk-approval-toolbar__select-all">
              <input
                checked={allFilteredApprovalsSelected}
                disabled={filteredApprovalEntries.length === 0 || isBulkApproving}
                onChange={toggleAllFilteredApprovals}
                ref={selectAllRef}
                type="checkbox"
              />
              <span>Select all filtered ({filteredApprovalEntries.length})</span>
            </label>
            <span className="registry-bulk-approval-toolbar__count">
              {selectedApprovalIds.size} selected
            </span>
            <button
              className="session-action"
              disabled={selectedApprovalIds.size === 0 || isBulkApproving || actionState !== null}
              onClick={() => void handleBulkApproval()}
              type="button"
            >
              <AppIcon name="verify" />
              {isBulkApproving
                ? 'Approving selected...'
                : `Approve selected (${selectedApprovalIds.size})`}
            </button>
          </div>

          {approvalError ? <div className="login-feedback login-feedback--error">{approvalError}</div> : null}
          {!isApprovalsLoading && approvalEntries.length === 0 && !approvalError ? (
            <p className="workspace-panel__copy">There are no migrant registrations pending final approval.</p>
          ) : null}
          {!isApprovalsLoading && approvalEntries.length > 0 && filteredApprovalEntries.length === 0 && !approvalError ? (
            <p className="workspace-panel__copy">No pending registrations match the current filters.</p>
          ) : null}

          <div className="signature-queue-list">
            {filteredApprovalEntries.map((entry) => {
              const isBusy = actionState?.entryId === entry.id
              const isSelected = selectedApprovalIds.has(entry.id)

              return (
                <article className={`signature-queue-card registry-approval-card${isSelected ? ' registry-approval-card--selected' : ''}`} key={entry.id}>
                  <label className="registry-approval-card__selector">
                    <input
                      aria-label={`Select ${formatEntryName(entry)} for bulk approval`}
                      checked={isSelected}
                      disabled={isBusy || isBulkApproving}
                      onChange={() => toggleApprovalSelection(entry.id)}
                      type="checkbox"
                    />
                  </label>
                  <div>
                    <strong>{formatEntryName(entry)}</strong>
                    <span>{formatEntrySubtitle(entry)}</span>
                    <span>{entry.pending_action === 'update' ? 'Modification' : 'New registration'}</span>
                    <small>Queued {new Date(entry.updated_at).toLocaleString()} by {entry.creator?.email ?? entry.created_by_role}</small>
                    <details className="registry-approval-card__details"><summary>Ver cuestionario completo</summary><MigrantQuestionnaireViewer payload={getApprovalPayload(entry)} /></details>
                  </div>
                  <div className="registry-approval-card__actions">
                    <button className="session-action session-action--quiet" disabled={isBusy || isBulkApproving} onClick={() => void handleApprovalDecision(entry, 'reject')} type="button">
                      <AppIcon name="delete" />
                      {isBusy && actionState?.action === 'reject' ? 'Rejecting...' : 'Reject'}
                    </button>
                    <button className="session-action" disabled={isBusy || isBulkApproving} onClick={() => void handleApprovalDecision(entry, 'approve')} type="button">
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
