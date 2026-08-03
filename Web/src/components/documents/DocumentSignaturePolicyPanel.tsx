import { useEffect, useMemo, useState } from 'react'

import { ApiRequestError } from '../../lib/api'
import {
  getDocumentSignaturePolicySignerOptions,
  updateDocumentRevisionSignaturePolicy,
  type DocumentDetailRevision,
  type DocumentSignaturePolicyRequirementInput,
  type DocumentSignaturePolicySignerOptions,
} from '../../lib/documents'
import { translate as t } from '../../lib/i18n'
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
    return t('Not available', 'No disponible')
  }

  const parsed = new Date(value)
  return Number.isNaN(parsed.getTime()) ? t('Not available', 'No disponible') : parsed.toLocaleString()
}

const formatSignerRole = (role: string) => {
  switch (role) {
    case 'admin':
      return t('Administrator', 'Administrador')
    case 'coordinator':
      return t('Coordinator', 'Coordinador')
    default:
      return role
  }
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
              : t('Signer options could not be loaded.', 'No se pudieron cargar las opciones de firmantes.'),
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
        message: t('Choose an account for every specific-user requirement.', 'Elige una cuenta para cada requisito asignado a una persona.'),
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
            : t('The signature policy could not be saved.', 'No se pudo guardar la política de firma.'),
      })
    } finally {
      setIsSaving(false)
    }
  }

  return (
    <section
      aria-label={t(`Signature policy for revision ${revision.revisionNumber}`, `Política de firma para la versión ${revision.revisionNumber}`)}
      className="signature-policy"
    >
      <div className="signature-policy__header">
        <div>
          <h4>{t('Signature policy', 'Política de firma')}</h4>
          <p>
            {requirements.length === 0
              ? t('Open signing is enabled for eligible administrators and coordinators.', 'La firma está abierta a las personas administradoras y coordinadoras que cumplan los requisitos.')
              : t(`${requirements.filter(isFulfilled).length} of ${requirements.length} required signatures completed.`, `${requirements.filter(isFulfilled).length} de ${requirements.length} firmas requeridas completadas.`)}
          </p>
        </div>
        <span className="document-badge">
          {signatureOrderEnforced ? t('Ordered', 'Orden obligatorio') : t('Any order', 'Cualquier orden')}
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
          {t('Enforce signing order', 'Exigir orden de firma')}
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
          {t('Add first step', 'Agregar primer paso')}
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
                      ? requirement.fulfilledByName ?? t('Unknown signer', 'Firmante desconocido')
                      : requirement.type === 'user'
                        ? requirement.fulfilledByName ?? t('Assigned signer', 'Firmante asignado')
                      : requirement.role === 'admin'
                        ? t('Administrator', 'Administrador')
                        : t('Coordinator', 'Coordinador')}
                  </strong>
                  <span>
                    {fulfilled
                      ? t(`Completed ${formatDateTime(requirement.fulfilledAt)}`, `Completado el ${formatDateTime(requirement.fulfilledAt)}`)
                      : t('Pending', 'Pendiente')}
                  </span>
                </div>
              ) : (
                <>
                  <select
                    aria-label={t(`Requirement ${index + 1} type`, `Tipo del requisito ${index + 1}`)}
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
                    <option value="role">{t('Role', 'Rol')}</option>
                    <option value="user">{t('Specific user', 'Persona específica')}</option>
                  </select>
                  {requirement.type === 'role' ? (
                    <select
                      aria-label={t(`Requirement ${index + 1} role`, `Rol del requisito ${index + 1}`)}
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
                          {formatSignerRole(role.value)}
                        </option>
                      ))}
                    </select>
                  ) : (
                    <select
                      aria-label={t(`Requirement ${index + 1} user`, `Persona del requisito ${index + 1}`)}
                      disabled={isSaving || isLoadingOptions}
                      onChange={(event) =>
                        updateRequirement(index, {
                          userId: Number(event.currentTarget.value) || null,
                        })
                      }
                      value={requirement.userId ?? ''}
                    >
                      <option value="">{t('Choose signer', 'Selecciona a la persona firmante')}</option>
                      {userOptions.map((option) => (
                        <option key={option.id} value={option.id}>
                          {option.name} ({formatSignerRole(option.role)})
                        </option>
                      ))}
                    </select>
                  )}
                  <div className="signature-policy__row-actions">
                    <button
                      aria-label={t(`Move requirement ${index + 1} up`, `Mover el requisito ${index + 1} hacia arriba`)}
                      className="signature-policy__icon-button"
                      disabled={isSaving || previousLocked}
                      onClick={() => moveRequirement(index, -1)}
                      title={t('Move up', 'Mover hacia arriba')}
                      type="button"
                    >
                      <AppIcon name="moveUp" />
                    </button>
                    <button
                      aria-label={t(`Move requirement ${index + 1} down`, `Mover el requisito ${index + 1} hacia abajo`)}
                      className="signature-policy__icon-button"
                      disabled={isSaving || nextLocked}
                      onClick={() => moveRequirement(index, 1)}
                      title={t('Move down', 'Mover hacia abajo')}
                      type="button"
                    >
                      <AppIcon name="moveDown" />
                    </button>
                    <button
                      aria-label={t(`Remove requirement ${index + 1}`, `Eliminar el requisito ${index + 1}`)}
                      className="signature-policy__icon-button signature-policy__icon-button--danger"
                      disabled={isSaving}
                      onClick={() =>
                        setRequirements((current) =>
                          current.filter((_, itemIndex) => itemIndex !== index),
                        )
                      }
                      title={t('Remove requirement', 'Eliminar requisito')}
                      type="button"
                    >
                      <AppIcon name="delete" />
                    </button>
                  </div>
                </>
              )}

              {canManage ? (
                <button
                  aria-label={t(`Insert requirement after step ${index + 1}`, `Insertar un requisito después del paso ${index + 1}`)}
                  className="signature-policy__insert"
                  disabled={isSaving || requirements.length >= 20}
                  onClick={() => insertRequirement(index + 1)}
                  title={t('Insert requirement after this step', 'Insertar un requisito después de este paso')}
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
          {t('Add signature requirement', 'Agregar requisito de firma')}
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
              {t('Reload policy', 'Volver a cargar la política')}
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
            {isSaving ? t('Saving policy...', 'Guardando la política...') : t('Save policy', 'Guardar política')}
          </button>
        </div>
      ) : null}
    </section>
  )
}
