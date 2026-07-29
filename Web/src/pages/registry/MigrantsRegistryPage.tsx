import { useCallback, useEffect, useState } from 'react'

import { MigrantRegistryForm } from '../../components/registry/MigrantRegistryForm'
import { AppIcon } from '../../components/ui/AppIcon'
import { APP_HOME_PATH, APP_MIGRANT_REGISTRATIONS_PATH, APP_MIGRANT_REGISTRY_PATH } from '../../config/appRoutes'
import { migrantDocumentsEnabled } from '../../config/env'
import type { AuthenticatedUser } from '../../lib/auth'
import type { PendingMigrantDocument } from '../../lib/migrantDocuments'
import {
  ApiRequestError,
  createRegistryDraft,
  discardRegistryDraft,
  getRegistryDrafts,
  getRegistryEntryById,
  submitRegistryDraft,
  type MigrantRegistrationPayload,
  type RegistryEntry,
  type RegistryStatus,
  updateRegistryDraft,
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
    canDownloadArcoApproved: role === 'non_coordinator',
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

type EntryFormMode = 'correction' | 'draft' | 'edit'

const getEntryFormRequest = (locationSearch?: string) => {
  const params = new URLSearchParams(locationSearch ?? '')
  const entryId = Number(params.get('entryId'))

  if (!Number.isInteger(entryId) || entryId <= 0) {
    return null
  }

  return {
    entryId,
    mode: params.get('mode') === 'edit' ? 'edit' : params.get('mode') === 'draft' ? 'draft' : 'correction',
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
  const [drafts, setDrafts] = useState<RegistryEntry[]>([])
  const [draftsLoading, setDraftsLoading] = useState(true)
  const [draftError, setDraftError] = useState<string | null>(null)

  const loadDrafts = useCallback(async () => {
    setDraftsLoading(true)
    setDraftError(null)
    try {
      const response = await getRegistryDrafts()
      setDrafts(response.data)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
      } else {
        setDraftError(error instanceof Error ? error.message : 'No fue posible cargar los borradores.')
      }
    } finally {
      setDraftsLoading(false)
    }
  }, [onSessionExpired])

  useEffect(() => { void loadDrafts() }, [loadDrafts])

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
        const isAvailableDraft = formMode === 'draft' &&
          response.data.current_status === 'draft' &&
          response.data.created_by === user.id

        if (!isAvailableCorrection && !isAvailableEdit && !isAvailableDraft) {
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
    draftId: number | null,
  ) => {
    if (draftId === null) throw new Error('El borrador debe guardarse antes de enviar el registro.')
    await submitRegistryDraft(draftId, payload_json, documents)
    await loadDrafts()
    if (formMode === 'draft') onNavigate?.(APP_MIGRANT_REGISTRY_PATH)
  }

  const handleSaveDraft = async (payload_json: MigrantRegistrationPayload, draftId: number | null) => {
    const response = draftId === null
      ? await createRegistryDraft(payload_json)
      : await updateRegistryDraft(draftId, payload_json)
    return response.data
  }

  const handleDiscardDraft = async (draft: RegistryEntry) => {
    if (!window.confirm(`Eliminar ${String(draft.payload_json.fullName || `borrador #${draft.id}`)}?`)) return

    setDraftError(null)
    try {
      await discardRegistryDraft(draft.id)
      await loadDrafts()
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setDraftError(error instanceof Error ? error.message : 'No fue posible eliminar el borrador.')
    }
  }

  const handleUpdateRequest = async (
    payload_json: MigrantRegistrationPayload,
    documents: PendingMigrantDocument[],
    draftId: number | null,
  ) => {
    void draftId
    if (!requestedEntry) {
      return
    }

    await updateRegistryEntry(requestedEntry.id, { payload_json }, documents)
    onNavigate?.(formMode === 'edit' ? APP_MIGRANT_REGISTRATIONS_PATH : APP_HOME_PATH)
  }

  const isRequestedEntryReady = requestedEntryId !== null && requestedEntry?.id === requestedEntryId
  const isCorrection = isRequestedEntryReady && formMode === 'correction'
  const isEditRequest = isRequestedEntryReady && formMode === 'edit'
  const isDraft = isRequestedEntryReady && formMode === 'draft'
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

        {!isCorrection && !isEditRequest && !isDraft ? (
          <section className="registry-drafts" aria-label="Mis borradores">
            <div className="registry-drafts__header"><div><h3>Mis borradores</h3><p>Los borradores se eliminan después de siete días sin actividad.</p></div><button className="session-action session-action--quiet" disabled={draftsLoading} onClick={() => void loadDrafts()} type="button"><AppIcon name="refresh" />Actualizar</button></div>
            {draftError ? <div className="login-feedback login-feedback--error">{draftError}</div> : null}
            {!draftsLoading && drafts.length === 0 ? <p>No hay borradores pendientes.</p> : null}
            {drafts.length > 0 ? <div className="registry-drafts__list">{drafts.map((draft) => <article key={draft.id}><div><strong>{String(draft.payload_json.fullName || `Borrador #${draft.id}`)}</strong><span>Actualizado {new Date(draft.updated_at).toLocaleString()}</span><small>Expira {draft.expires_at ? new Date(draft.expires_at).toLocaleString() : 'en siete días'}</small></div><div className="registry-form__actions"><button className="session-action session-action--quiet" onClick={() => void handleDiscardDraft(draft)} type="button"><AppIcon name="delete" />Eliminar</button><button className="session-action" onClick={() => onNavigate?.(`${APP_MIGRANT_REGISTRY_PATH}?mode=draft&entryId=${draft.id}`)} type="button"><AppIcon name="document" />Continuar</button></div></article>)}</div> : null}
          </section>
        ) : null}

        {isLoadingEntry ? <p className="workspace-panel__copy">Loading registration...</p> : null}
        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

        {!isLoadingEntry && (!requestedEntryId || isRequestedEntryReady) ? (
          <MigrantRegistryForm
            documentContext={isRequestedEntryReady && requestedEntry
              ? {
                  canDelete: getDocumentPermissions(user, requestedEntry).canDelete,
                  canDownload: getDocumentPermissions(user, requestedEntry).canDownload,
                  canDownloadArcoApproved: getDocumentPermissions(user, requestedEntry).canDownloadArcoApproved,
                  canUpload: getDocumentPermissions(user, requestedEntry).canUpload,
                  canView: getDocumentPermissions(user, requestedEntry).canView,
                  entryId: requestedEntry.id,
                  onSessionExpired,
                }
              : null}
            documentsEnabled={migrantDocumentsEnabled}
            draftEntryId={isDraft ? requestedEntry?.id : null}
            draftsEnabled={!isCorrection && !isEditRequest}
            initialPayload={requestedEntry?.payload_json ?? null}
            onCancel={isRequestedEntryReady
              ? () => onNavigate?.(isEditRequest ? APP_MIGRANT_REGISTRATIONS_PATH : APP_HOME_PATH)
              : undefined}
            onSubmit={isRequestedEntryReady && !isDraft ? handleUpdateRequest : handleCreate}
            onDraftSaved={() => void loadDrafts()}
            onSaveDraft={!isCorrection && !isEditRequest ? handleSaveDraft : undefined}
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
