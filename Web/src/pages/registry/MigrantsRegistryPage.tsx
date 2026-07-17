import { useEffect, useState } from 'react'

import { MigrantRegistryForm } from '../../components/registry/MigrantRegistryForm'
import { APP_HOME_PATH, APP_MIGRANT_REGISTRATIONS_PATH } from '../../config/appRoutes'
import { migrantDocumentsEnabled } from '../../config/env'
import type { AuthenticatedUser } from '../../lib/auth'
import type { PendingMigrantDocument } from '../../lib/migrantDocuments'
import {
  ApiRequestError,
  createRegistryEntry,
  getRegistryEntryById,
  type MigrantRegistrationPayload,
  type RegistryEntry,
  type RegistryStatus,
  updateRegistryEntry,
} from '../../lib/registry'

const DOCUMENT_PRE_APPROVAL_STATUSES: RegistryStatus[] = [
  'pending_review',
  'pending_approval',
  'changes_requested',
]

const DOCUMENT_VOLUNTEER_STATUSES: RegistryStatus[] = ['pending_review', 'changes_requested']

const getDocumentPermissions = (user: AuthenticatedUser, entry: RegistryEntry) => {
  const status = entry.current_status
  const role = user.role
  const owns = entry.created_by === user.id
  const isReviewer = role === 'admin' || role === 'coordinator' || role === 'non_coordinator'
  const preApproval = DOCUMENT_PRE_APPROVAL_STATUSES.includes(status)
  const volunteerCanTouch = role === 'volunteer' && owns && DOCUMENT_VOLUNTEER_STATUSES.includes(status)

  return {
    canDelete: (preApproval && isReviewer) || volunteerCanTouch,
    canDownload: role === 'admin' || role === 'coordinator',
    canUpload:
      (preApproval && isReviewer) ||
      volunteerCanTouch ||
      (status === 'approved' && (role === 'admin' || role === 'coordinator' || role === 'non_coordinator')),
    canView: role !== 'volunteer',
  }
}

type MigrantsRegistryPageProps = {
  locationSearch?: string
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type EntryFormMode = 'correction' | 'edit'

const getEntryFormRequest = (locationSearch?: string) => {
  const params = new URLSearchParams(locationSearch ?? '')
  const entryId = Number(params.get('entryId'))

  if (!Number.isInteger(entryId) || entryId <= 0) {
    return null
  }

  return {
    entryId,
    mode: params.get('mode') === 'edit' ? 'edit' : 'correction',
  } satisfies { entryId: number, mode: EntryFormMode }
}

export function MigrantsRegistryPage({
  locationSearch,
  onNavigate,
  onSessionExpired,
  user,
}: MigrantsRegistryPageProps) {
  const entryFormRequest = getEntryFormRequest(locationSearch)
  const requestedEntryId = entryFormRequest?.entryId ?? null
  const formMode = entryFormRequest?.mode ?? null
  const [requestedEntry, setRequestedEntry] = useState<RegistryEntry | null>(null)
  const [loadError, setLoadError] = useState<{ entryId: number, message: string } | null>(null)

  useEffect(() => {
    if (requestedEntryId === null || formMode === null) {
      return
    }

    let isMounted = true

    getRegistryEntryById(requestedEntryId)
      .then((response) => {
        if (!isMounted) {
          return
        }

        const isAvailableCorrection = formMode === 'correction' &&
          response.data.current_status === 'changes_requested' &&
          response.data.created_by === user.id
        const isAvailableEdit = formMode === 'edit' &&
          user.role === 'non_coordinator' &&
          response.data.current_status === 'approved'

        if (!isAvailableCorrection && !isAvailableEdit) {
          setRequestedEntry(null)
          setLoadError({
            entryId: requestedEntryId,
            message: formMode === 'edit'
              ? 'This approved registration is not available for an edit request in your session.'
              : 'This registration is not available for correction in your session.',
          })
          return
        }

        setRequestedEntry(response.data)
        setLoadError(null)
      })
      .catch((loadError) => {
        if (!isMounted) {
          return
        }

        if (loadError instanceof ApiRequestError && loadError.status === 401) {
          onSessionExpired?.()
          return
        }

        setLoadError({
          entryId: requestedEntryId,
          message: loadError instanceof Error ? loadError.message : 'Unable to load the registration for editing.',
        })
      })

    return () => {
      isMounted = false
    }
  }, [formMode, onSessionExpired, requestedEntryId, user.id, user.role])

  const handleCreate = async (
    payload_json: MigrantRegistrationPayload,
    documents: PendingMigrantDocument[],
  ) => {
    await createRegistryEntry({ payload_json }, documents)
  }

  const handleUpdateRequest = async (
    payload_json: MigrantRegistrationPayload,
    documents: PendingMigrantDocument[],
  ) => {
    if (!requestedEntry) {
      return
    }

    await updateRegistryEntry(requestedEntry.id, { payload_json }, documents)
    onNavigate?.(formMode === 'edit' ? APP_MIGRANT_REGISTRATIONS_PATH : APP_HOME_PATH)
  }

  const isRequestedEntryReady = requestedEntryId !== null && requestedEntry?.id === requestedEntryId
  const isCorrection = isRequestedEntryReady && formMode === 'correction'
  const isEditRequest = isRequestedEntryReady && formMode === 'edit'
  const error = loadError?.entryId === requestedEntryId ? loadError.message : null
  const isLoadingEntry = requestedEntryId !== null && !isRequestedEntryReady && !error

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">
          {isCorrection ? 'Correct registration' : isEditRequest ? 'Request registration edit' : 'Registration intake'}
        </h2>
        <p className="workspace-panel__copy">
          {isCorrection
            ? 'Address the reviewer feedback, then resubmit the registration for review.'
            : isEditRequest
              ? 'Propose changes to the approved record. The current data remains active until the request completes review and final approval.'
            : 'New submissions enter non-coordinator review before coordinator approval.'}
        </p>

        {isLoadingEntry ? <p className="workspace-panel__copy">Loading registration...</p> : null}
        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

        {!isLoadingEntry && (!requestedEntryId || isRequestedEntryReady) ? (
          <MigrantRegistryForm
            documentContext={isRequestedEntryReady && requestedEntry
              ? {
                  canDelete: getDocumentPermissions(user, requestedEntry).canDelete,
                  canDownload: getDocumentPermissions(user, requestedEntry).canDownload,
                  canUpload: getDocumentPermissions(user, requestedEntry).canUpload,
                  canView: getDocumentPermissions(user, requestedEntry).canView,
                  entryId: requestedEntry.id,
                  onSessionExpired,
                }
              : null}
            documentsEnabled={migrantDocumentsEnabled}
            initialPayload={requestedEntry?.payload_json ?? null}
            onCancel={isRequestedEntryReady
              ? () => onNavigate?.(isEditRequest ? APP_MIGRANT_REGISTRATIONS_PATH : APP_HOME_PATH)
              : undefined}
            onSubmit={isRequestedEntryReady ? handleUpdateRequest : handleCreate}
            submitLabel={isCorrection ? 'Resubmit registration' : isEditRequest ? 'Submit edit request' : 'Submit registration'}
            successMessage={isCorrection
              ? 'Registration resubmitted for review.'
              : isEditRequest
                ? 'Edit request submitted for review.'
                : 'Registration submitted for review.'}
          />
        ) : null}
      </section>
    </section>
  )
}
