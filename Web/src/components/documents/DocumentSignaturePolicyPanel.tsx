import { useEffect, useMemo, useState } from 'react'

import { ApiRequestError } from '../../lib/api'
import {
  getDocumentSignaturePolicySignerOptions,
  updateDocumentRevisionSignaturePolicy,
  type DocumentDetailRevision,
  type DocumentSignaturePolicyRequirementInput,
  type DocumentSignaturePolicySignerOptions,
} from '../../lib/documents'
import { AppIcon } from '../ui/AppIcon'

type DocumentSignaturePolicyPanelProps = {
  documentId: number
  onReload: () => void
  onSaved: () => Promise<void>
  onSessionExpired?: () => void
  revision: DocumentDetailRevision
}

type DraftRequirement = {
  fulfilledAt?: string | null
  fulfilledByName?: string | null
  id?: number
  key: string
  role: 'admin' | 'coordinator'
  type: 'role' | 'user'
  userId: number | null
}

const toDraftRequirements = (
  revision: DocumentDetailRevision,
): DraftRequirement[] =>
  revision.signaturePolicy.requirements.map((requirement) => ({
    fulfilledAt: requirement.fulfilledAt,
    fulfilledByName:
      requirement.fulfilledBy?.name ?? requirement.signerUser?.name ?? null,
    id: requirement.id,
    key: `stored-${requirement.id}`,
    role: requirement.signerRole === 'coordinator' ? 'coordinator' : 'admin',
    type: requirement.type,
    userId: requirement.signerUser?.id ?? null,
  }))

const isFulfilled = (requirement: DraftRequirement) =>
  Boolean(requirement.fulfilledAt)

const formatDateTime = (value?: string | null) => {
  if (!value) {
    return 'Not available'
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? 'Not available' : parsed.toLocaleString()
}

export function DocumentSignaturePolicyPanel({
  documentId,
  onReload,
  onSaved,
  onSessionExpired,
  revision,
}: DocumentSignaturePolicyPanelProps) {
  const canManage = revision.capabilities.canManageSignaturePolicy
  const [requirements, setRequirements] = useState<DraftRequirement[]>(() =>
    toDraftRequirements(revision),
  )
  const [signatureOrderEnforced, setSignatureOrderEnforced] = useState(
    revision.signaturePolicy.signatureOrderEnforced,
  )
  const [options, setOptions] =
    useState<DocumentSignaturePolicySignerOptions | null>(null)
  const [isLoadingOptions, setIsLoadingOptions] = useState(canManage)
  const [isSaving, setIsSaving] = useState(false)
  const [feedback, setFeedback] = useState<{
    kind: 'error' | 'stale' | 'success'
    message: string
  } | null>(null)

  useEffect(() => {
    setRequirements(toDraftRequirements(revision))
    setSignatureOrderEnforced(
      revision.signaturePolicy.signatureOrderEnforced,
    )
    setFeedback(null)
  }, [revision])

  useEffect(() => {
    if (!canManage) {
      setOptions(null)
      setIsLoadingOptions(false)
      return
    }

    let isMounted = true
    setIsLoadingOptions(true)

    getDocumentSignaturePolicySignerOptions()
      .then((response) => {
        if (isMounted) {
          setOptions(response)
          setIsLoadingOptions(false)
        }
      })
      .catch((error) => {
        if (!isMounted) {
          return
        }
        if (error instanceof ApiRequestError && error.status === 401) {
          onSessionExpired?.()
          return
        }
        setFeedback({
          kind: 'error',
          message:
            error instanceof Error
              ? error.message
              : 'Signer options could not be loaded.',
        })
        setIsLoadingOptions(false)
      })

    return () => {
      isMounted = false
    }
  }, [canManage, onSessionExpired])

  const userOptions = options?.users ?? []
  const hasIncompleteAssignment = useMemo(
    () =>
      requirements.some(
        (requirement) =>
          !isFulfilled(requirement) &&
          requirement.type === 'user' &&
          requirement.userId == null,
      ),
    [requirements],
  )

  const insertRequirement = (index: number) => {
    setRequirements((current) => {
      const next = [...current]
      next.splice(index, 0, {
        key: `new-${Date.now()}-${index}`,
        role: 'coordinator',
        type: 'role',
        userId: null,
      })
      return next
    })
    setFeedback(null)
  }

  const updateRequirement = (
    index: number,
    update: Partial<DraftRequirement>,
  ) => {
    setRequirements((current) =>
      current.map((requirement, itemIndex) =>
        itemIndex === index ? { ...requirement, ...update } : requirement,
      ),
    )
    setFeedback(null)
  }

  const moveRequirement = (index: number, offset: -1 | 1) => {
    const targetIndex = index + offset
    if (
      targetIndex < 0 ||
      targetIndex >= requirements.length ||
      isFulfilled(requirements[index]) ||
      isFulfilled(requirements[targetIndex])
    ) {
      return
    }

    setRequirements((current) => {
      const next = [...current]
      ;[next[index], next[targetIndex]] = [next[targetIndex], next[index]]
      return next
    })
    setFeedback(null)
  }

  const handleSave = async () => {
    if (hasIncompleteAssignment || isSaving) {
      setFeedback({
        kind: 'error',
        message: 'Choose an account for every specific-user requirement.',
      })
      return
    }

    const payloadRequirements: DocumentSignaturePolicyRequirementInput[] =
      requirements.map((requirement) =>
        requirement.type === 'role'
          ? {
              id: requirement.id,
              role: requirement.role,
              type: 'role',
            }
          : {
              id: requirement.id,
              type: 'user',
              userId: requirement.userId as number,
            },
      )

    setIsSaving(true)
    setFeedback(null)
    try {
      const response = await updateDocumentRevisionSignaturePolicy(
        documentId,
        revision.id,
        {
          expectedVersion: revision.signaturePolicy.version,
          requirements: payloadRequirements,
          signatureOrderEnforced,
        },
      )
      await onSaved()
      setFeedback({ kind: 'success', message: response.message })
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setFeedback({
        kind:
          error instanceof ApiRequestError && error.status === 409
            ? 'stale'
            : 'error',
        message:
          error instanceof Error
            ? error.message
            : 'The signature policy could not be saved.',
      })
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <section
      aria-label={`Signature policy for revision ${revision.revisionNumber}`}
      className="signature-policy"
    >
      <div className="signature-policy__header">
        <div>
          <h4>Signature policy</h4>
          <p>
            {requirements.length === 0
              ? 'Open signing is enabled for eligible administrators and coordinators.'
              : `${requirements.filter(isFulfilled).length} of ${requirements.length} required signatures completed.`}
          </p>
        </div>
        <span className="document-badge">
          {signatureOrderEnforced ? 'Ordered' : 'Any order'}
        </span>
      </div>

      {canManage ? (
        <label className="signature-policy__toggle">
          <input
            checked={signatureOrderEnforced}
            disabled={isSaving}
            onChange={(event) =>
              setSignatureOrderEnforced(event.currentTarget.checked)
            }
            type="checkbox"
          />
          Enforce signing order
        </label>
      ) : null}

      {canManage && requirements.length > 0 ? (
        <button
          className="session-action session-action--quiet session-action--inline"
          disabled={isSaving || requirements.length >= 20}
          onClick={() => insertRequirement(0)}
          type="button"
        >
          <AppIcon name="plus" />
          Add first step
        </button>
      ) : null}

      <ol className="signature-policy__requirements">
        {requirements.map((requirement, index) => {
          const fulfilled = isFulfilled(requirement)
          const previousLocked =
            index === 0 || isFulfilled(requirements[index - 1])
          const nextLocked =
            index === requirements.length - 1 ||
            isFulfilled(requirements[index + 1])

          return (
            <li
              className={
                fulfilled
                  ? 'signature-policy__requirement signature-policy__requirement--fulfilled'
                  : 'signature-policy__requirement'
              }
              key={requirement.key}
            >
              <span className="signature-policy__sequence">{index + 1}</span>
              {fulfilled || !canManage ? (
                <div className="signature-policy__summary">
                  <strong>
                    {fulfilled
                      ? requirement.fulfilledByName ?? 'Unknown signer'
                      : requirement.type === 'user'
                        ? requirement.fulfilledByName ?? 'Assigned signer'
                      : requirement.role === 'admin'
                        ? 'Administrator'
                        : 'Coordinator'}
                  </strong>
                  <span>
                    {fulfilled
                      ? `Completed ${formatDateTime(requirement.fulfilledAt)}`
                      : 'Pending'}
                  </span>
                </div>
              ) : (
                <>
                  <select
                    aria-label={`Requirement ${index + 1} type`}
                    disabled={isSaving}
                    onChange={(event) =>
                      updateRequirement(index, {
                        type: event.currentTarget.value as 'role' | 'user',
                        userId:
                          event.currentTarget.value === 'user'
                            ? userOptions[0]?.id ?? null
                            : null,
                      })
                    }
                    value={requirement.type}
                  >
                    <option value="role">Role</option>
                    <option value="user">Specific user</option>
                  </select>
                  {requirement.type === 'role' ? (
                    <select
                      aria-label={`Requirement ${index + 1} role`}
                      disabled={isSaving}
                      onChange={(event) =>
                        updateRequirement(index, {
                          role: event.currentTarget.value as
                            | 'admin'
                            | 'coordinator',
                        })
                      }
                      value={requirement.role}
                    >
                      {(options?.roles ?? []).map((role) => (
                        <option key={role.value} value={role.value}>
                          {role.label}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <select
                      aria-label={`Requirement ${index + 1} user`}
                      disabled={isSaving || isLoadingOptions}
                      onChange={(event) =>
                        updateRequirement(index, {
                          userId: Number(event.currentTarget.value) || null,
                        })
                      }
                      value={requirement.userId ?? ''}
                    >
                      <option value="">Choose signer</option>
                      {userOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name} ({option.role})
                        </option>
                      ))}
                    </select>
                  )}
                  <div className="signature-policy__row-actions">
                    <button
                      aria-label={`Move requirement ${index + 1} up`}
                      className="signature-policy__icon-button"
                      disabled={isSaving || previousLocked}
                      onClick={() => moveRequirement(index, -1)}
                      title="Move up"
                      type="button"
                    >
                      <AppIcon name="moveUp" />
                    </button>
                    <button
                      aria-label={`Move requirement ${index + 1} down`}
                      className="signature-policy__icon-button"
                      disabled={isSaving || nextLocked}
                      onClick={() => moveRequirement(index, 1)}
                      title="Move down"
                      type="button"
                    >
                      <AppIcon name="moveDown" />
                    </button>
                    <button
                      aria-label={`Remove requirement ${index + 1}`}
                      className="signature-policy__icon-button signature-policy__icon-button--danger"
                      disabled={isSaving}
                      onClick={() =>
                        setRequirements((current) =>
                          current.filter((_, itemIndex) => itemIndex !== index),
                        )
                      }
                      title="Remove requirement"
                      type="button"
                    >
                      <AppIcon name="delete" />
                    </button>
                  </div>
                </>
              )}

              {canManage ? (
                <button
                  aria-label={`Insert requirement after step ${index + 1}`}
                  className="signature-policy__insert"
                  disabled={isSaving || requirements.length >= 20}
                  onClick={() => insertRequirement(index + 1)}
                  title="Insert requirement after this step"
                  type="button"
                >
                  <AppIcon name="plus" size={15} />
                </button>
              ) : null}
            </li>
          )
        })}
      </ol>

      {canManage && requirements.length === 0 ? (
        <button
          className="workspace-action workspace-action--secondary"
          disabled={isSaving}
          onClick={() => insertRequirement(0)}
          type="button"
        >
          <AppIcon name="plus" />
          Add signature requirement
        </button>
      ) : null}

      {feedback ? (
        <div
          className={`login-feedback ${
            feedback.kind === 'success'
              ? 'login-feedback--success'
              : 'login-feedback--error'
          }`}
        >
          {feedback.message}
          {feedback.kind === 'stale' ? (
            <button
              className="session-action session-action--quiet session-action--inline"
              onClick={onReload}
              type="button"
            >
              <AppIcon name="refresh" />
              Reload policy
            </button>
          ) : null}
        </div>
      ) : null}

      {canManage ? (
        <div className="workspace-actions">
          <button
            className="workspace-action"
            disabled={isSaving || isLoadingOptions || hasIncompleteAssignment}
            onClick={handleSave}
            type="button"
          >
            <AppIcon name="verify" />
            {isSaving ? 'Saving policy...' : 'Save policy'}
          </button>
        </div>
      ) : null}
    </section>
  )
}
