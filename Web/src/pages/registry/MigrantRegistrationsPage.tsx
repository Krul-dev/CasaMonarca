import { useEffect, useMemo, useState } from 'react'

import { MigrantDocumentsPanel } from '../../components/registry/MigrantDocumentsPanel'
import { AppIcon } from '../../components/ui/AppIcon'
import { APP_MIGRANT_REGISTRY_PATH } from '../../config/appRoutes'
import { migrantDocumentsEnabled } from '../../config/env'
import type { AuthenticatedUser } from '../../lib/auth'
import {
  ApiRequestError,
  getRegistryEntries,
  type RegistryEntry,
} from '../../lib/registry'

type MigrantRegistrationsPageProps = {
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type RegistrationsFilterState = {
  country: string
  page: number
  pageSize: number
  populationGroup: string
  search: string
  status: string
}

const PAGE_SIZE_OPTIONS = [10, 25, 50, 100]
const REGISTRATIONS_FILTER_SESSION_KEY = 'casa-monarca.migrant-registrations.filters'

const DEFAULT_FILTERS: RegistrationsFilterState = {
  country: '',
  page: 1,
  pageSize: 25,
  populationGroup: '',
  search: '',
  status: '',
}

const formatDate = (value?: string | null) => {
  if (!value) {
    return 'Not available'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'Not available'
  }

  return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(date)
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

const formatValue = (value?: unknown, fallback = 'Not available') => {
  if (typeof value !== 'string' || value.trim() === '') {
    return fallback
  }

  return value.replace(/_/g, ' ')
}

const formatStatus = (value: string) => formatValue(value, 'Unknown status')

const getEntryName = (entry: RegistryEntry) =>
  formatValue(
    entry.payload_json.fullName ?? entry.payload_json.full_name,
    `Registration #${entry.id}`,
  )

const getEntryCountry = (entry: RegistryEntry) =>
  formatValue(entry.payload_json.countryOfOrigin)

const getEntryPopulationGroup = (entry: RegistryEntry) =>
  formatValue(entry.payload_json.populationGroup)

const getSearchableValue = (entry: RegistryEntry) => [
  entry.id,
  entry.created_by_role,
  entry.creator?.email,
  entry.creator?.name,
  entry.current_status,
  entry.payload_json.fullName,
  entry.payload_json.full_name,
  entry.payload_json.firstName,
  entry.payload_json.firstLastName,
  entry.payload_json.secondLastName,
  entry.payload_json.countryOfOrigin,
  entry.payload_json.departmentState,
  entry.payload_json.populationGroup,
  entry.payload_json.phone,
]
  .filter((value): value is string | number => typeof value === 'string' || typeof value === 'number')
  .join(' ')
  .toLocaleLowerCase()

const normalizeFilterValue = (value: unknown) =>
  typeof value === 'string' ? value.trim() : ''

const readStoredFilters = (): RegistrationsFilterState => {
  if (typeof window === 'undefined') {
    return DEFAULT_FILTERS
  }

  try {
    const rawValue = window.sessionStorage.getItem(REGISTRATIONS_FILTER_SESSION_KEY)

    if (!rawValue) {
      return DEFAULT_FILTERS
    }

    const stored = JSON.parse(rawValue) as Partial<RegistrationsFilterState>
    const page = Number(stored.page)
    const pageSize = Number(stored.pageSize)

    return {
      country: normalizeFilterValue(stored.country),
      page: Number.isInteger(page) && page > 0 ? page : DEFAULT_FILTERS.page,
      pageSize: PAGE_SIZE_OPTIONS.includes(pageSize) ? pageSize : DEFAULT_FILTERS.pageSize,
      populationGroup: normalizeFilterValue(stored.populationGroup),
      search: normalizeFilterValue(stored.search),
      status: normalizeFilterValue(stored.status),
    }
  } catch {
    return DEFAULT_FILTERS
  }
}

const storeFilters = (filters: RegistrationsFilterState) => {
  try {
    window.sessionStorage.setItem(REGISTRATIONS_FILTER_SESSION_KEY, JSON.stringify(filters))
  } catch {
    // Filter persistence is a convenience only; browsing must work without storage.
  }
}

const getUniqueValues = (entries: RegistryEntry[], getValue: (entry: RegistryEntry) => string) =>
  [...new Set(entries.map(getValue).filter((value) => value !== 'Not available'))]
    .sort((first, second) => first.localeCompare(second))

export function MigrantRegistrationsPage({ onNavigate, onSessionExpired, user }: MigrantRegistrationsPageProps) {
  const [initialFilters] = useState<RegistrationsFilterState>(() => readStoredFilters())
  const [entries, setEntries] = useState<RegistryEntry[]>([])
  const [error, setError] = useState<string | null>(null)
  const [isLoading, setIsLoading] = useState(true)
  const [countryFilter, setCountryFilter] = useState(initialFilters.country)
  const [populationGroupFilter, setPopulationGroupFilter] = useState(initialFilters.populationGroup)
  const [statusFilter, setStatusFilter] = useState(initialFilters.status)
  const [page, setPage] = useState(initialFilters.page)
  const [pageSize, setPageSize] = useState(initialFilters.pageSize)
  const [searchInput, setSearchInput] = useState(initialFilters.search)
  const [debouncedSearch, setDebouncedSearch] = useState(initialFilters.search)
  const [reloadToken, setReloadToken] = useState(0)
  const [documentEntryIds, setDocumentEntryIds] = useState<Set<number>>(() => new Set())

  useEffect(() => {
    const timeoutId = window.setTimeout(() => {
      const nextSearch = searchInput.trim()

      if (nextSearch !== debouncedSearch) {
        setDebouncedSearch(nextSearch)
        setPage(1)
      }
    }, 300)

    return () => window.clearTimeout(timeoutId)
  }, [debouncedSearch, searchInput])

  useEffect(() => {
    let isMounted = true

    getRegistryEntries()
      .then((response) => {
        if (!isMounted) {
          return
        }

        setEntries(response.data)
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

        setEntries([])
        setError(loadError instanceof Error ? loadError.message : 'Unable to load migrant registrations.')
        setIsLoading(false)
      })

    return () => {
      isMounted = false
    }
  }, [onSessionExpired, reloadToken])

  useEffect(() => {
    storeFilters({
      country: countryFilter,
      page,
      pageSize,
      populationGroup: populationGroupFilter,
      search: debouncedSearch,
      status: statusFilter,
    })
  }, [countryFilter, debouncedSearch, page, pageSize, populationGroupFilter, statusFilter])

  const countries = useMemo(() => getUniqueValues(entries, getEntryCountry), [entries])
  const populationGroups = useMemo(
    () => getUniqueValues(entries, getEntryPopulationGroup),
    [entries],
  )
  const statuses = useMemo(
    () => [...new Set(entries.map((entry) => entry.current_status))].sort(),
    [entries],
  )
  const filteredEntries = useMemo(() => {
    const searchTerm = debouncedSearch.toLocaleLowerCase()

    return entries.filter((entry) =>
      (statusFilter === '' || entry.current_status === statusFilter) &&
      (countryFilter === '' || getEntryCountry(entry) === countryFilter) &&
      (populationGroupFilter === '' || getEntryPopulationGroup(entry) === populationGroupFilter) &&
      (searchTerm === '' || getSearchableValue(entry).includes(searchTerm)),
    )
  }, [countryFilter, debouncedSearch, entries, populationGroupFilter, statusFilter])

  const totalPages = Math.max(1, Math.ceil(filteredEntries.length / pageSize))
  const currentPage = Math.min(page, totalPages)
  const firstVisibleRegistration = filteredEntries.length === 0 ? 0 : (currentPage - 1) * pageSize + 1
  const lastVisibleRegistration = Math.min(currentPage * pageSize, filteredEntries.length)
  const visibleEntries = filteredEntries.slice((currentPage - 1) * pageSize, currentPage * pageSize)
  const hasActiveFilters = Boolean(
    countryFilter || populationGroupFilter || statusFilter || debouncedSearch,
  )

  const refresh = () => {
    setError(null)
    setIsLoading(true)
    setReloadToken((current) => current + 1)
  }

  const resetFilters = () => {
    setCountryFilter('')
    setPopulationGroupFilter('')
    setStatusFilter('')
    setSearchInput('')
    setDebouncedSearch('')
    setPage(1)
  }

  const revealDocuments = (entryId: number) => {
    setDocumentEntryIds((current) => {
      if (current.has(entryId)) {
        return current
      }

      return new Set(current).add(entryId)
    })
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <div className="audit-toolbar">
          <div>
            <h2 className="workspace-panel__title">Current migrant registrations</h2>
            <p className="workspace-panel__copy">
              Browse the shared registry and review the current details attached to each registration.
            </p>
          </div>

          <button className="audit-toolbar__button" disabled={isLoading} onClick={refresh} type="button">
            <AppIcon name="refresh" />
            {isLoading ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>

        <div aria-label="Migrant registration filters" className="audit-controls">
          <label className="audit-control audit-control--search">
            <span>Search</span>
            <input
              onChange={(event) => setSearchInput(event.target.value)}
              placeholder="Name, country, contact, submitter, or ID"
              type="search"
              value={searchInput}
            />
          </label>

          <label className="audit-control">
            <span>Status</span>
            <select
              onChange={(event) => {
                setStatusFilter(event.target.value)
                setPage(1)
              }}
              value={statusFilter}
            >
              <option value="">All statuses</option>
              {statuses.map((status) => (
                <option key={status} value={status}>{formatStatus(status)}</option>
              ))}
            </select>
          </label>

          <label className="audit-control">
            <span>Country</span>
            <select
              onChange={(event) => {
                setCountryFilter(event.target.value)
                setPage(1)
              }}
              value={countryFilter}
            >
              <option value="">All countries</option>
              {countries.map((country) => <option key={country} value={country}>{country}</option>)}
            </select>
          </label>

          <label className="audit-control audit-control--size">
            <span>Rows</span>
            <select
              onChange={(event) => {
                setPageSize(Number(event.target.value))
                setPage(1)
              }}
              value={pageSize}
            >
              {PAGE_SIZE_OPTIONS.map((size) => <option key={size} value={size}>{size}</option>)}
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

        <label className="registry-browser__population-filter audit-control">
          <span>Population group</span>
          <select
            onChange={(event) => {
              setPopulationGroupFilter(event.target.value)
              setPage(1)
            }}
            value={populationGroupFilter}
          >
            <option value="">All population groups</option>
            {populationGroups.map((group) => <option key={group} value={group}>{group}</option>)}
          </select>
        </label>

        <div className="audit-pagination">
          <p>
            Showing <strong>{firstVisibleRegistration}-{lastVisibleRegistration}</strong> of{' '}
            <strong>{filteredEntries.length}</strong>
            {filteredEntries.length !== entries.length ? ` filtered from ${entries.length}` : ''}
          </p>

          <div className="audit-pagination__actions">
            <button disabled={isLoading || currentPage === 1} onClick={() => setPage(currentPage - 1)} type="button">
              Previous
            </button>
            <span>Page {currentPage} of {totalPages}</span>
            <button disabled={isLoading || currentPage === totalPages} onClick={() => setPage(currentPage + 1)} type="button">
              Next
            </button>
          </div>
        </div>

        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
        {isLoading ? <p className="workspace-panel__copy">Loading current registrations...</p> : null}
        {!isLoading && !error && visibleEntries.length === 0 ? (
          <p className="workspace-panel__copy">
            {hasActiveFilters ? 'No registrations match the current filters.' : 'No current migrant registrations are available.'}
          </p>
        ) : null}
      </section>

      {!isLoading && !error ? (
        <section className="registry-browser__feed">
          {visibleEntries.map((entry) => (
            <article className={`registry-browser__card registry-browser__card--${entry.current_status}`} key={entry.id}>
              <div className="registry-browser__header">
                <div>
                  <h3 className="workspace-panel__title">{getEntryName(entry)}</h3>
                  <p>Registration #{entry.id} · received {formatDateTime(entry.created_at)}</p>
                </div>
                <div className="audit-card__badges">
                  <span className="registry-browser__badge">{getEntryPopulationGroup(entry)}</span>
                  <span className="registry-browser__status">{formatStatus(entry.current_status)}</span>
                </div>
              </div>

              {user.role === 'non_coordinator' && entry.current_status === 'approved' ? (
                <div className="registry-browser__actions">
                  <button
                    className="session-action session-action--quiet session-action--inline"
                    onClick={() => onNavigate?.(`${APP_MIGRANT_REGISTRY_PATH}?mode=edit&entryId=${entry.id}`)}
                    type="button"
                  >
                    <AppIcon name="document" />
                    Request edit
                  </button>
                </div>
              ) : null}

              <div className="registry-browser__context">
                <span><small>Origin</small><strong>{getEntryCountry(entry)}</strong></span>
                <span><small>State</small><strong>{formatValue(entry.payload_json.departmentState)}</strong></span>
                <span><small>Attention date</small><strong>{formatDate(entry.payload_json.attentionDate)}</strong></span>
                <span><small>Submitted by</small><strong>{entry.creator?.email ?? formatValue(entry.created_by_role)}</strong></span>
              </div>

              <details
                className="registry-browser__details"
                onToggle={(event) => {
                  if (event.currentTarget.open) {
                    revealDocuments(entry.id)
                  }
                }}
              >
                <summary>View registration details</summary>
                <dl>
                  <div><dt>First name</dt><dd>{formatValue(entry.payload_json.firstName)}</dd></div>
                  <div><dt>First last name</dt><dd>{formatValue(entry.payload_json.firstLastName)}</dd></div>
                  <div><dt>Second last name</dt><dd>{formatValue(entry.payload_json.secondLastName)}</dd></div>
                  <div><dt>Birth date</dt><dd>{formatDate(entry.payload_json.birthDate)}</dd></div>
                  <div><dt>Gender</dt><dd>{formatValue(entry.payload_json.gender)}</dd></div>
                  <div><dt>Civil status</dt><dd>{formatValue(entry.payload_json.civilStatus)}</dd></div>
                  <div><dt>Phone</dt><dd>{formatValue(entry.payload_json.phone)}</dd></div>
                  <div><dt>Last updated</dt><dd>{formatDateTime(entry.updated_at)}</dd></div>
                </dl>
                {typeof entry.payload_json.notes === 'string' && entry.payload_json.notes.trim() ? (
                  <p className="registry-browser__notes"><small>Notes</small>{entry.payload_json.notes}</p>
                ) : null}
                {migrantDocumentsEnabled && user.role !== 'volunteer' && documentEntryIds.has(entry.id) ? (
                  <section className="registry-browser__documents">
                    <h4>Supporting documents</h4>
                    <MigrantDocumentsPanel
                      canDelete={false}
                      canDownload={user.role === 'admin' || user.role === 'coordinator'}
                      canView
                      embedded
                      entryId={entry.id}
                      onSessionExpired={onSessionExpired}
                    />
                  </section>
                ) : null}
              </details>
            </article>
          ))}
        </section>
      ) : null}
    </section>
  )
}
