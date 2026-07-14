import { useEffect, useState } from 'react'

import { AppIcon } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import { APP_ADMIN_PATH, APP_DOCUMENTS_PATH } from '../config/appRoutes'
import { ApiRequestError } from '../lib/api'
import type { UserRole } from '../lib/auth'
import {
  getAuditEvents,
  type AuditEventCategory,
  type AuditEventPagination,
  type AuditEventSummary,
} from '../lib/auditEvents'

type LoggingPageProps = {
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
}

const formatDateTime = (value?: string | null) => {
  if (!value) {
    return 'Not available'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'Not available'
  }

  return new Intl.DateTimeFormat('en-US', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

const formatEventLabel = (value: string) =>
  value
    .split('.')
    .map((segment) => segment.replace(/_/g, ' '))
    .map((segment) => segment.charAt(0).toUpperCase() + segment.slice(1))
    .join(' / ')

const formatOutcomeLabel = (value: AuditEventSummary['outcome']) =>
  value.charAt(0).toUpperCase() + value.slice(1)

type AuditClearanceLevel = 'admin' | 'authenticated' | 'destructive' | 'passkey' | 'routine'

type AuditClearance = {
  label: string
  level: AuditClearanceLevel
}

const DEFAULT_PAGINATION: AuditEventPagination = {
  hasNextPage: false,
  hasPreviousPage: false,
  limit: 25,
  page: 1,
  total: 0,
  totalPages: 1,
}

const AUDIT_CATEGORIES: Array<{ label: string; value: AuditEventCategory }> = [
  { label: 'Account invites', value: 'account' },
  { label: 'Admin actions', value: 'admin' },
  { label: 'Authentication', value: 'auth' },
  { label: 'Documents', value: 'document' },
  { label: 'Security challenges', value: 'security' },
  { label: 'VCS', value: 'vcs' },
]

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100]

type LoggingFeedState = {
  categoryFilter: AuditEventCategory | ''
  outcomeFilter: AuditEventSummary['outcome'] | ''
  page: number
  pageSize: number
  search: string
}

const LOGGING_FILTER_SESSION_KEY = 'casa-monarca.logging.filters'

const DEFAULT_LOGGING_FILTERS: LoggingFeedState = {
  categoryFilter: '',
  outcomeFilter: '',
  page: 1,
  pageSize: 25,
  search: '',
}

const isUserRole = (value: unknown): value is UserRole =>
  value === 'admin' ||
  value === 'coordinator' ||
  value === 'non_coordinator' ||
  value === 'volunteer'

const isAuditCategory = (value: unknown): value is AuditEventCategory =>
  AUDIT_CATEGORIES.some((category) => category.value === value)

const isAuditOutcome = (value: unknown): value is AuditEventSummary['outcome'] =>
  value === 'denied' || value === 'failure' || value === 'success'

const readStoredLoggingFilters = (): LoggingFeedState => {
  if (typeof window === 'undefined') {
    return DEFAULT_LOGGING_FILTERS
  }

  try {
    const rawValue = window.sessionStorage.getItem(LOGGING_FILTER_SESSION_KEY)

    if (!rawValue) {
      return DEFAULT_LOGGING_FILTERS
    }

    const parsedValue = JSON.parse(rawValue) as Partial<LoggingFeedState>
    const page = Number(parsedValue.page)
    const pageSize = Number(parsedValue.pageSize)

    return {
      categoryFilter: isAuditCategory(parsedValue.categoryFilter) ? parsedValue.categoryFilter : '',
      outcomeFilter: isAuditOutcome(parsedValue.outcomeFilter) ? parsedValue.outcomeFilter : '',
      page: Number.isInteger(page) && page > 0 ? page : 1,
      pageSize: PAGE_SIZE_OPTIONS.includes(pageSize) ? pageSize : 25,
      search: typeof parsedValue.search === 'string' ? parsedValue.search : '',
    }
  } catch {
    return DEFAULT_LOGGING_FILTERS
  }
}

const storeLoggingFilters = (state: LoggingFeedState) => {
  try {
    window.sessionStorage.setItem(LOGGING_FILTER_SESSION_KEY, JSON.stringify(state))
  } catch {
    // Filter persistence is a convenience only; the feed must still work without storage.
  }
}

const classifyClearance = (event: AuditEventSummary): AuditClearance => {
  const action = typeof event.metadata.action === 'string'
    ? event.metadata.action.toLowerCase()
    : ''
  const eventType = event.eventType.toLowerCase()

  if (
    eventType.includes('.delete') ||
    eventType.includes('cancellation.executed') ||
    eventType.includes('.disabled') ||
    eventType.includes('.passkeys_revoked') ||
    action === 'delete' ||
    action === 'suspend' ||
    action === 'revoke_passkeys'
  ) {
    return {
      label: 'Destructive',
      level: 'destructive',
    }
  }

  if (
    eventType.includes('challenge') ||
    eventType.includes('signed') ||
    eventType.includes('role_change') ||
    eventType.includes('totp_reset') ||
    eventType.includes('password_reset.issued') ||
    eventType.includes('package_signing_key')
  ) {
    return {
      label: 'Passkey-gated',
      level: 'passkey',
    }
  }

  if (
    eventType.startsWith('admin.') ||
    eventType.startsWith('account.invite') ||
    eventType.startsWith('vcs.')
  ) {
    return {
      label: 'Admin',
      level: 'admin',
    }
  }

  if (
    eventType.startsWith('auth.') ||
    eventType.startsWith('document.') ||
    eventType.startsWith('security.')
  ) {
    return {
      label: 'Authenticated',
      level: 'authenticated',
    }
  }

  return {
    label: 'Routine',
    level: 'routine',
  }
}

const asPositiveInteger = (value: unknown) => {
  if (typeof value !== 'number' || !Number.isInteger(value) || value <= 0) {
    return null
  }

  return value
}

const createAccountFocusToken = (userId: number) =>
  `${userId}-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`

const getAdminAccountUrl = (userId: number, focusToken?: string) => {
  const params = new URLSearchParams({
    tab: 'accounts',
    userId: String(userId),
  })

  if (focusToken) {
    params.set('focus', focusToken)
  }

  return `${APP_ADMIN_PATH}?${params.toString()}`
}

const getTargetUserId = (event: AuditEventSummary) => {
  const metadataTargetUserId = asPositiveInteger(event.metadata.targetUserId)

  if (metadataTargetUserId !== null) {
    return metadataTargetUserId
  }

  return event.resource.type === 'user' ? asPositiveInteger(event.resource.id) : null
}

const renderActor = (event: AuditEventSummary, onNavigate?: (to: string) => void) => {
  const roleBadge = isUserRole(event.actor.role)
    ? <RoleBadge role={event.actor.role} />
    : null
  const actorUserId = asPositiveInteger(event.actor.userId)
  const actorUrl = actorUserId !== null ? getAdminAccountUrl(actorUserId) : null

  const actorLabel = event.actor.name ?? event.actor.email

  if (actorLabel) {
    return (
      <>
        {actorUrl && actorUserId !== null ? (
          <a
            className="audit-entity-link"
            href={actorUrl}
            onClick={(clickEvent) => {
              if (!onNavigate) {
                return
              }

              clickEvent.preventDefault()
              onNavigate(getAdminAccountUrl(actorUserId, createAccountFocusToken(actorUserId)))
            }}
          >
            {actorLabel}
          </a>
        ) : actorLabel}
        {roleBadge ? <span className="audit-actor-role">{roleBadge}</span> : null}
      </>
    )
  }

  return 'Unknown actor'
}

const formatTargetUser = (metadata: Record<string, unknown>) => {
  if (typeof metadata.targetUserName === 'string') {
    return typeof metadata.targetUserEmail === 'string'
      ? `${metadata.targetUserName} (${metadata.targetUserEmail})`
      : metadata.targetUserName
  }

  if (typeof metadata.targetUserEmail === 'string') {
    return metadata.targetUserEmail
  }

  if (typeof metadata.targetUserId === 'number') {
    return `User #${metadata.targetUserId}`
  }

  return null
}

const formatDeviceCookieId = (value: string) =>
  value.startsWith('#') ? value : `#${value}`

const formatResource = (event: AuditEventSummary) => {
  if (event.resource.type === 'document_revision' && event.resource.revisionId) {
    return `Revision #${event.resource.revisionId} on document #${event.resource.documentId ?? 'unknown'}`
  }

  if (event.resource.type === 'document' && event.resource.documentId) {
    return `Document #${event.resource.documentId}`
  }

  if (event.resource.type === 'session') {
    return 'Session'
  }

  if (event.resource.type === 'user') {
    return formatTargetUser(event.metadata) ?? `User #${event.resource.id ?? 'unknown'}`
  }

  return 'No resource'
}

const getDocumentEventUrl = (event: AuditEventSummary) => {
  const documentId = asPositiveInteger(event.resource.documentId)
    ?? (event.resource.type === 'document' ? asPositiveInteger(event.resource.id) : null)

  if (documentId === null) {
    return null
  }

  const params = new URLSearchParams({
    documentId: String(documentId),
  })
  const revisionId = asPositiveInteger(event.resource.revisionId)

  if (revisionId !== null) {
    params.set('revisionId', String(revisionId))
  }

  return `${APP_DOCUMENTS_PATH}?${params.toString()}`
}

const buildMetadataPreview = (event: AuditEventSummary) => {
  const metadata = event.metadata
  const previewEntries: Array<{
    href?: string
    label: string
    userId?: number
    value: string
  }> = []
  const targetUserId = getTargetUserId(event)

  if (typeof metadata.method === 'string') {
    previewEntries.push({ label: 'Method', value: metadata.method })
  }

  if (typeof metadata.deviceAlias === 'string') {
    previewEntries.push({ label: 'Browser', value: metadata.deviceAlias })
  }

  if (typeof metadata.deviceId === 'string') {
    previewEntries.push({ label: 'Cookie ID', value: formatDeviceCookieId(metadata.deviceId) })
  } else if (typeof metadata.deviceCookieId === 'string') {
    previewEntries.push({ label: 'Cookie ID', value: formatDeviceCookieId(metadata.deviceCookieId) })
  }

  if (typeof metadata.action === 'string') {
    previewEntries.push({ label: 'Action', value: metadata.action })
  }

  if (typeof metadata.actionLabel === 'string') {
    previewEntries.push({ label: 'Action', value: metadata.actionLabel })
  }

  if (typeof metadata.requestType === 'string') {
    previewEntries.push({ label: 'ARCO right', value: metadata.requestType })
  }

  if (typeof metadata.purpose === 'string') {
    previewEntries.push({ label: 'Challenge purpose', value: metadata.purpose })
  }

  if (typeof metadata.challengeIntentId === 'string') {
    previewEntries.push({ label: 'Challenge intent', value: metadata.challengeIntentId })
  }

  if (typeof metadata.submittedEmail === 'string') {
    previewEntries.push({ label: 'Email', value: metadata.submittedEmail })
  }

  const targetUser = formatTargetUser(metadata)

  if (targetUser) {
    previewEntries.push({
      href: targetUserId !== null ? getAdminAccountUrl(targetUserId) : undefined,
      label: 'Target user',
      userId: targetUserId ?? undefined,
      value: targetUser,
    })
  }

  if (typeof metadata.previousRole === 'string' && typeof metadata.newRole === 'string') {
    previewEntries.push({ label: 'Role change', value: `${metadata.previousRole} -> ${metadata.newRole}` })
  } else if (typeof metadata.previousRole === 'string' && typeof metadata.targetRole === 'string') {
    previewEntries.push({ label: 'Requested role', value: `${metadata.previousRole} -> ${metadata.targetRole}` })
  }

  if (typeof metadata.previousStatus === 'string' && typeof metadata.newStatus === 'string') {
    previewEntries.push({ label: 'Status change', value: `${metadata.previousStatus} -> ${metadata.newStatus}` })
  } else if (typeof metadata.previousStatus === 'string' && typeof metadata.status === 'string') {
    previewEntries.push({ label: 'Status change', value: `${metadata.previousStatus} -> ${metadata.status}` })
  } else if (typeof metadata.previousStatus === 'string' && typeof metadata.action === 'string') {
    previewEntries.push({ label: 'Requested status action', value: `${metadata.action} from ${metadata.previousStatus}` })
  }

  if (typeof metadata.reason === 'string' && metadata.reason.trim() !== '') {
    previewEntries.push({ label: 'Reason', value: metadata.reason })
  }

  if (typeof metadata.originalFileName === 'string') {
    previewEntries.push({ label: 'File', value: metadata.originalFileName })
  }

  if (typeof metadata.revisionNumber === 'number') {
    previewEntries.push({ label: 'Revision', value: String(metadata.revisionNumber) })
  }

  if (typeof metadata.signatureId === 'number') {
    previewEntries.push({ label: 'Signature ID', value: String(metadata.signatureId) })
  }

  if (typeof metadata.tombstoneId === 'number') {
    previewEntries.push({ label: 'Tombstone ID', value: String(metadata.tombstoneId) })
  }

  if (event.request.userAgent) {
    previewEntries.push({ label: 'Agent', value: event.request.userAgent })
  }

  return previewEntries
}

export function LoggingPage({ onNavigate, onSessionExpired }: LoggingPageProps) {
  const [initialFilters] = useState<LoggingFeedState>(() => readStoredLoggingFilters())
  const [events, setEvents] = useState<AuditEventSummary[]>([])
  const [error, setError] = useState<string | null>(null)
  const [categoryFilter, setCategoryFilter] = useState<AuditEventCategory | ''>(initialFilters.categoryFilter)
  const [debouncedSearch, setDebouncedSearch] = useState(initialFilters.search)
  const [isLoading, setIsLoading] = useState(true)
  const [outcomeFilter, setOutcomeFilter] = useState<AuditEventSummary['outcome'] | ''>(initialFilters.outcomeFilter)
  const [page, setPage] = useState(initialFilters.page)
  const [pageSize, setPageSize] = useState(initialFilters.pageSize)
  const [pagination, setPagination] = useState<AuditEventPagination>(DEFAULT_PAGINATION)
  const [reloadToken, setReloadToken] = useState(0)
  const [searchInput, setSearchInput] = useState(initialFilters.search)

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      const nextSearch = searchInput.trim()

      if (nextSearch === debouncedSearch) {
        return
      }

      setIsLoading(true)
      setError(null)
      setDebouncedSearch(nextSearch)
      setPage(1)
    }, 300)

    return () => {
      window.clearTimeout(timeoutId)
    }
  }, [debouncedSearch, searchInput])

  useEffect(() => {
    let isMounted = true

    getAuditEvents({
      category: categoryFilter || undefined,
      limit: pageSize,
      outcome: outcomeFilter || undefined,
      page,
      q: debouncedSearch,
    })
      .then((response) => {
        if (!isMounted) {
          return
        }

        setEvents(response.events)
        setPagination(response.pagination)
        setIsLoading(false)
      })
      .catch((loadError) => {
        if (!isMounted) {
          return
        }

        if (loadError instanceof ApiRequestError && loadError.status === 401) {
          onSessionExpired?.()
          return
        }

        setError(
          loadError instanceof Error
            ? loadError.message
            : 'Failed to load audit events.',
        )
        setPagination(DEFAULT_PAGINATION)
        setIsLoading(false)
      })

    return () => {
      isMounted = false
    }
  }, [categoryFilter, debouncedSearch, onSessionExpired, outcomeFilter, page, pageSize, reloadToken])

  useEffect(() => {
    storeLoggingFilters({
      categoryFilter,
      outcomeFilter,
      page,
      pageSize,
      search: debouncedSearch,
    })
  }, [categoryFilter, debouncedSearch, outcomeFilter, page, pageSize])

  const refreshFeed = () => {
    setIsLoading(true)
    setError(null)
    setReloadToken((current) => current + 1)
  }

  const firstVisibleEvent = pagination.total === 0
    ? 0
    : (pagination.page - 1) * pagination.limit + 1
  const lastVisibleEvent = Math.min(pagination.page * pagination.limit, pagination.total)
  const hasActiveFilters = categoryFilter !== '' || outcomeFilter !== '' || debouncedSearch !== ''

  const resetFilters = () => {
    setIsLoading(true)
    setError(null)
    setCategoryFilter('')
    setOutcomeFilter('')
    setSearchInput('')
    setDebouncedSearch('')
    setPage(1)
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <div className="audit-toolbar">
          <div>
            <h2 className="workspace-panel__title">Admin audit feed</h2>
            <p className="workspace-panel__copy">
              Recent append-only security and document activity from the API.
            </p>
          </div>

          <button
            className="audit-toolbar__button"
            onClick={refreshFeed}
            type="button"
          >
            <AppIcon name="refresh" />
            Refresh feed
          </button>
        </div>

        <div className="audit-controls" aria-label="Audit event filters">
          <label className="audit-control audit-control--search">
            <span>Search</span>
            <input
              onChange={(event) => {
                setSearchInput(event.target.value)
              }}
              placeholder="Event, actor, resource, or IP"
              type="search"
              value={searchInput}
            />
          </label>

          <label className="audit-control">
            <span>Outcome</span>
            <select
              onChange={(event) => {
                setIsLoading(true)
                setError(null)
                setOutcomeFilter(event.target.value as AuditEventSummary['outcome'] | '')
                setPage(1)
              }}
              value={outcomeFilter}
            >
              <option value="">All outcomes</option>
              <option value="success">Success</option>
              <option value="failure">Failure</option>
              <option value="denied">Denied</option>
            </select>
          </label>

          <label className="audit-control">
            <span>Category</span>
            <select
              onChange={(event) => {
                setIsLoading(true)
                setError(null)
                setCategoryFilter(event.target.value as AuditEventCategory | '')
                setPage(1)
              }}
              value={categoryFilter}
            >
              <option value="">All categories</option>
              {AUDIT_CATEGORIES.map((category) => (
                <option key={category.value} value={category.value}>
                  {category.label}
                </option>
              ))}
            </select>
          </label>

          <label className="audit-control audit-control--size">
            <span>Rows</span>
            <select
              onChange={(event) => {
                setIsLoading(true)
                setError(null)
                setPageSize(Number(event.target.value))
                setPage(1)
              }}
              value={pageSize}
            >
              {PAGE_SIZE_OPTIONS.map((size) => (
                <option key={size} value={size}>
                  {size}
                </option>
              ))}
            </select>
          </label>

          <button
            className="audit-controls__reset"
            disabled={!hasActiveFilters}
            onClick={resetFilters}
            type="button"
          >
            Clear filters
          </button>
        </div>

        <div className="audit-pagination">
          <p>
            Showing <strong>{firstVisibleEvent}-{lastVisibleEvent}</strong> of{' '}
            <strong>{pagination.total}</strong>
          </p>

          <div className="audit-pagination__actions">
            <button
              disabled={isLoading || !pagination.hasPreviousPage}
              onClick={() => {
                setIsLoading(true)
                setError(null)
                setPage((currentPage) => Math.max(1, currentPage - 1))
              }}
              type="button"
            >
              Previous
            </button>
            <span>
              Page {pagination.page} of {pagination.totalPages}
            </span>
            <button
              disabled={isLoading || !pagination.hasNextPage}
              onClick={() => {
                setIsLoading(true)
                setError(null)
                setPage((currentPage) => currentPage + 1)
              }}
              type="button"
            >
              Next
            </button>
          </div>
        </div>

        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

        {isLoading ? (
          <p className="workspace-panel__copy">Loading audit events...</p>
        ) : null}

        {!isLoading && !error && events.length === 0 ? (
          <p className="workspace-panel__copy">
            {hasActiveFilters
              ? 'No audit events match the current filters.'
              : 'No audit events are recorded yet in this environment.'}
          </p>
        ) : null}
      </section>

      {!isLoading && !error ? (
        <section className="audit-feed">
          {events.map((event) => {
            const metadataPreview = buildMetadataPreview(event)
            const clearance = classifyClearance(event)
            const documentUrl = getDocumentEventUrl(event)

            return (
              <article
                className={`audit-card audit-card--${clearance.level}`}
                key={event.id}
              >
                <div className="audit-card__header">
                  <h3 className="workspace-panel__title">
                    {formatEventLabel(event.eventType)}
                  </h3>

                  <div className="audit-card__badges">
                    <span className={`audit-clearance audit-clearance--${clearance.level}`}>
                      {clearance.label}
                    </span>
                    <span className={`audit-badge audit-badge--${event.outcome}`}>
                      {formatOutcomeLabel(event.outcome)}
                    </span>
                  </div>
                </div>

                <div className="audit-card__context">
                  <span className="audit-context-item">
                    <span>Occurred</span>
                    <strong>{formatDateTime(event.occurredAt)}</strong>
                  </span>
                  <span className="audit-context-item">
                    <span>Actor</span>
                    <strong>{renderActor(event, onNavigate)}</strong>
                  </span>
                  <span className="audit-context-item">
                    <span>Resource</span>
                    <strong>
                      {documentUrl ? (
                        <a
                          className="audit-entity-link"
                          href={documentUrl}
                          onClick={(clickEvent) => {
                            if (!onNavigate) {
                              return
                            }

                            clickEvent.preventDefault()
                            onNavigate(documentUrl)
                          }}
                        >
                          {formatResource(event)}
                        </a>
                      ) : (
                        formatResource(event)
                      )}
                    </strong>
                  </span>
                  <span className="audit-context-item">
                    <span>IP</span>
                    <strong>{event.request.ipAddress || 'Not available'}</strong>
                  </span>
                </div>

                <div className="audit-card__footer">
                  {metadataPreview.length > 0 ? (
                    <div className="audit-metadata-preview">
                      {metadataPreview.map((entry) => {
                        const content = (
                          <>
                            <span>{entry.label}</span>
                            <strong>{entry.value}</strong>
                          </>
                        )

                        const entryHref = entry.href

                        return entryHref ? (
                          <a
                            className="audit-metadata-chip audit-metadata-chip--link"
                            href={entryHref}
                            key={`${entry.label}:${entry.value}`}
                            onClick={(clickEvent) => {
                              if (!onNavigate) {
                                return
                              }

                              clickEvent.preventDefault()
                              onNavigate(
                                entry.userId
                                  ? getAdminAccountUrl(entry.userId, createAccountFocusToken(entry.userId))
                                  : entryHref,
                              )
                            }}
                          >
                            {content}
                          </a>
                        ) : (
                          <span className="audit-metadata-chip" key={`${entry.label}:${entry.value}`}>
                            {content}
                          </span>
                        )
                      })}
                    </div>
                  ) : (
                    <span className="audit-metadata-empty">No metadata preview</span>
                  )}

                  <details className="audit-card__details">
                    <summary>Raw</summary>
                    <pre className="audit-card__code">
                      {JSON.stringify(event.metadata, null, 2)}
                    </pre>
                  </details>
                </div>
              </article>
            )
          })}
        </section>
      ) : null}
    </section>
  )
}
