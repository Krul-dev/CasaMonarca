import { useEffect, useState } from 'react'

import { MigrantRegistryForm } from '../../components/registry/MigrantRegistryForm'
import type { AuthenticatedUser } from '../../lib/auth'
import {
  ApiRequestError,
  createRegistryEntry,
  getRegistryEntryById,
  type MigrantRegistrationPayload,
  type RegistryEntry,
  updateRegistryEntry,
} from '../../lib/registry'

type MigrantsRegistryPageProps = {
  locationSearch?: string
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

const getCorrectionEntryId = (locationSearch?: string) => {
  const entryId = Number(new URLSearchParams(locationSearch ?? '').get('entryId'))

  return Number.isInteger(entryId) && entryId > 0 ? entryId : null
}

export function MigrantsRegistryPage({
  locationSearch,
  onNavigate,
  onSessionExpired,
  user,
}: MigrantsRegistryPageProps) {
  const correctionEntryId = getCorrectionEntryId(locationSearch)
  const [correctionEntry, setCorrectionEntry] = useState<RegistryEntry | null>(null)
  const [loadError, setLoadError] = useState<{ entryId: number, message: string } | null>(null)

  useEffect(() => {
    if (correctionEntryId === null) {
      return
    }

    let isMounted = true

    getRegistryEntryById(correctionEntryId)
      .then((response) => {
        if (!isMounted) {
          return
        }

        if (
          response.data.current_status !== 'changes_requested' ||
          response.data.created_by !== user.id
        ) {
          setCorrectionEntry(null)
          setLoadError({
            entryId: correctionEntryId,
            message: 'This registration is not available for correction in your session.',
          })
          return
        }

        setCorrectionEntry(response.data)
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
          entryId: correctionEntryId,
          message: loadError instanceof Error ? loadError.message : 'Unable to load the registration for correction.',
        })
      })

    return () => {
      isMounted = false
    }
  }, [correctionEntryId, onSessionExpired, user.id])

  const handleCreate = async (payload_json: MigrantRegistrationPayload) => {
    await createRegistryEntry({ payload_json })
  }

  const handleCorrection = async (payload_json: MigrantRegistrationPayload) => {
    if (!correctionEntry) {
      return
    }

    await updateRegistryEntry(correctionEntry.id, { payload_json })
    onNavigate?.('/app/migrants/registry')
  }

  const isCorrection = correctionEntryId !== null && correctionEntry?.id === correctionEntryId
  const error = loadError?.entryId === correctionEntryId ? loadError.message : null
  const isLoadingCorrection = correctionEntryId !== null && !isCorrection && !error

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">
          {isCorrection ? 'Correct registration' : 'Registration intake'}
        </h2>
        <p className="workspace-panel__copy">
          {isCorrection
            ? 'Address the reviewer feedback, then resubmit the registration for review.'
            : 'New submissions enter non-coordinator review before coordinator approval.'}
        </p>

        {isLoadingCorrection ? <p className="workspace-panel__copy">Loading registration for correction...</p> : null}
        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

        {!isLoadingCorrection && (!correctionEntryId || isCorrection) ? (
          <MigrantRegistryForm
            initialPayload={correctionEntry?.payload_json ?? null}
            onCancel={isCorrection ? () => onNavigate?.('/app') : undefined}
            onSubmit={isCorrection ? handleCorrection : handleCreate}
            submitLabel={isCorrection ? 'Resubmit registration' : 'Submit registration'}
            successMessage={isCorrection ? 'Registration resubmitted for review.' : 'Registration submitted for review.'}
          />
        ) : null}
      </section>
    </section>
  )
}
