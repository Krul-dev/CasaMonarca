import { translate as t } from '../lib/i18n'
import { useEffect, useMemo, useState } from 'react'

import { AppIcon } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import { appChannel } from '../config/env'
import { ApiRequestError } from '../lib/api'
import {
  createAccountInvite,
  getAccountInvites,
  issueAccountInviteLink,
  revokeAccountInvite,
  verifyAccountInviteOutOfBand,
  type AccountInviteSummary,
  type InviteRole,
  type InviteStatus,
  type InviteVerificationMethod,
} from '../lib/accountInvites'
import type { AuthenticatedUser } from '../lib/auth'

type InviteManagementPageProps = {
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type CreateInviteForm = {
  email: string
  role: InviteRole
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

const formatRoleLabel = (role: InviteRole) => {
  if (role === 'admin') {
    return 'Admin'
  }

  if (role === 'coordinator') {
    return 'Coordinator'
  }

  if (role === 'non_coordinator') {
    return 'Non Coordinator'
  }

  return 'Volunteer'
}

const formatStatusLabel = (status: string) =>
  status.charAt(0).toUpperCase() + status.slice(1).replace(/_/g, ' ')

const asInviteStatus = (status: string): InviteStatus | null => {
  switch (status) {
    case 'draft':
    case 'verified':
    case 'issued':
    case 'expired':
    case 'redeemed':
    case 'revoked':
      return status
    default:
      return null
  }
}

const getDefaultInviteRole = (userRole: AuthenticatedUser['role']): InviteRole =>
  userRole === 'admin' ? 'coordinator' : 'non_coordinator'

const getStatusClassName = (status: string) => {
  switch (status) {
    case 'issued':
    case 'verified':
      return 'document-badge invite-badge invite-badge--online'
    case 'redeemed':
      return 'document-badge invite-badge invite-badge--neutral'
    case 'expired':
      return 'document-badge invite-badge invite-badge--warning'
    case 'revoked':
      return 'document-badge invite-badge invite-badge--offline'
    default:
      return 'document-badge invite-badge'
  }
}

export function InviteManagementPage({
  onSessionExpired,
  user,
}: InviteManagementPageProps) {
  const [invites, setInvites] = useState<AccountInviteSummary[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [isCreating, setIsCreating] = useState(false)
  const [activeInviteId, setActiveInviteId] = useState<number | null>(null)
  const [issueExpiryHours, setIssueExpiryHours] = useState('24')
  const [verificationMethodByInvite, setVerificationMethodByInvite] = useState<
    Record<number, InviteVerificationMethod>
  >({})
  const [verificationNoteByInvite, setVerificationNoteByInvite] = useState<
    Record<number, string>
  >({})
  const [issuedLinkByInvite, setIssuedLinkByInvite] = useState<Record<number, string>>({})
  const [createForm, setCreateForm] = useState<CreateInviteForm>({
    email: '',
    role: getDefaultInviteRole(user.role),
  })
  const canCreateTemporaryAdminInvites =
    user.role === 'admin' && appChannel?.toLowerCase() === 'dev'

  const allowedRoles = useMemo<InviteRole[]>(() => {
    if (user.role === 'admin') {
      return canCreateTemporaryAdminInvites
        ? ['admin', 'coordinator', 'non_coordinator', 'volunteer']
        : ['coordinator', 'non_coordinator', 'volunteer']
    }

    return ['non_coordinator', 'volunteer']
  }, [canCreateTemporaryAdminInvites, user.role])

  useEffect(() => {
    let isMounted = true

    getAccountInvites()
      .then((response) => {
        if (!isMounted) {
          return
        }

        setInvites(response.invites)
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
            : 'Failed to load account invites.',
        )
        setIsLoading(false)
      })

    return () => {
      isMounted = false
    }
  }, [onSessionExpired, reloadToken])

  const refreshInvites = () => {
    setError(null)
    setSuccess(null)
    setIsLoading(true)
    setReloadToken((current) => current + 1)
  }

  const upsertInvite = (invite: AccountInviteSummary) => {
    setInvites((current) => {
      const next = [...current]
      const index = next.findIndex((entry) => entry.id === invite.id)

      if (index >= 0) {
        next[index] = {
          ...next[index],
          ...invite,
        }
      } else {
        next.unshift(invite)
      }

      return next
    })
  }

  const handleCreateInvite = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const normalizedEmail = createForm.email.trim().toLowerCase()

    if (!normalizedEmail) {
      setError(t("Enter the target email before creating an invite.", "Ingresa el correo de destino antes de crear una invitación."))
      setSuccess(null)
      return
    }

    if (!allowedRoles.includes(createForm.role)) {
      setError(t("This role is not allowed for the current account.", "Este rol no está permitido para la cuenta actual."))
      setSuccess(null)
      return
    }

    setIsCreating(true)
    setError(null)
    setSuccess(null)

    try {
      const response = await createAccountInvite({
        email: normalizedEmail,
        role: createForm.role,
      })

      upsertInvite(response.invite)
      setCreateForm({
        email: '',
        role: getDefaultInviteRole(user.role),
      })
      setSuccess(response.message)
    } catch (createError) {
      if (createError instanceof ApiRequestError && createError.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(
        createError instanceof Error
          ? createError.message
          : t("Failed to create invite draft.", "No se pudo crear el borrador de invitación."),
      )
    } finally {
      setIsCreating(false)
    }
  }

  const handleRecordVerification = async (invite: AccountInviteSummary) => {
    setActiveInviteId(invite.id)
    setError(null)
    setSuccess(null)

    try {
      const response = await verifyAccountInviteOutOfBand(invite.id, {
        method: verificationMethodByInvite[invite.id] ?? 'phone',
        note: verificationNoteByInvite[invite.id]?.trim() || undefined,
      })

      upsertInvite(response.invite)
      setSuccess(response.message)
    } catch (verifyError) {
      if (verifyError instanceof ApiRequestError && verifyError.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(
        verifyError instanceof Error
          ? verifyError.message
          : t("Failed to record out-of-band verification.", "No se pudo registrar la verificación fuera de banda."),
      )
    } finally {
      setActiveInviteId(null)
    }
  }

  const handleIssueLink = async (invite: AccountInviteSummary) => {
    const expiresInHours = Number.parseInt(issueExpiryHours, 10)

    if (!Number.isFinite(expiresInHours) || expiresInHours < 1 || expiresInHours > 48) {
      setError(t("Issue duration must be between 1 and 48 hours.", "La duración del enlace debe estar entre 1 y 48 horas."))
      setSuccess(null)
      return
    }

    setActiveInviteId(invite.id)
    setError(null)
    setSuccess(null)

    try {
      const response = await issueAccountInviteLink(invite.id, {
        expiresInHours,
      })

      upsertInvite(response.invite)
      setIssuedLinkByInvite((current) => ({
        ...current,
        [invite.id]: new URL(response.invite.registrationPath, window.location.origin).toString(),
      }))
      setSuccess(response.message)
    } catch (issueError) {
      if (issueError instanceof ApiRequestError && issueError.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(
        issueError instanceof Error
          ? issueError.message
          : t("Failed to issue registration link.", "No se pudo emitir el enlace de registro."),
      )
    } finally {
      setActiveInviteId(null)
    }
  }

  const handleRevoke = async (invite: AccountInviteSummary) => {
    setActiveInviteId(invite.id)
    setError(null)
    setSuccess(null)

    try {
      const response = await revokeAccountInvite(invite.id)
      upsertInvite(response.invite)
      setSuccess(response.message)
    } catch (revokeError) {
      if (revokeError instanceof ApiRequestError && revokeError.status === 401) {
        onSessionExpired?.()
        return
      }

      setError(
        revokeError instanceof Error
          ? revokeError.message
          : t("Failed to revoke invite.", "No se pudo revocar la invitación."),
      )
    } finally {
      setActiveInviteId(null)
    }
  }

  const handleCopyIssuedLink = async (inviteId: number) => {
    const link = issuedLinkByInvite[inviteId]

    if (!link) {
      return
    }

    try {
      await navigator.clipboard.writeText(link)
      setSuccess(t("Invite link copied to clipboard.", "Enlace de invitación copiado al portapapeles."))
      setError(null)
    } catch {
      setError(t("Clipboard access is unavailable. Copy the link manually.", "El acceso al portapapeles no está disponible. Copia el enlace manualmente."))
      setSuccess(null)
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <div className="audit-toolbar">
          <div>
            <h2 className="workspace-panel__title">{t("Account invites", "Invitaciones de cuenta")}</h2>
            <p className="workspace-panel__copy">
              {t("Create role-bound invites, record out-of-band verification, issue short-lived registration links, and revoke pending invites. ", "Crea invitaciones vinculadas a un rol, registra verificaciones fuera de banda, emite enlaces de registro de corta duración y revoca invitaciones pendientes. ")}</p>
          </div>

          <button
            className="audit-toolbar__button"
            onClick={refreshInvites}
            type="button"
          >
            <AppIcon name="refresh" />
            {t("Refresh invites ", "Actualizar invitaciones ")}</button>
        </div>

        <form className="invite-create-form" onSubmit={handleCreateInvite}>
          <label className="login-field">
            <span className="login-field__label">{t("Target email", "Correo de destino")}</span>
            <input
              className="login-field__input"
              onChange={(event) =>
                setCreateForm((current) => ({
                  ...current,
                  email: event.target.value,
                }))
              }
              placeholder={t("new.user@casamonarca.local", "nuevo.usuario@casamonarca.local")}
              type="email"
              value={createForm.email}
            />
          </label>

          <label className="login-field">
            <span className="login-field__label">{t("Role", "Rol")}</span>
            <select
              className="login-field__input"
              onChange={(event) =>
                setCreateForm((current) => ({
                  ...current,
                  role: event.target.value as InviteRole,
                }))
              }
              value={createForm.role}
            >
              {allowedRoles.map((role) => (
                <option key={role} value={role}>
                  {role === 'admin'
                    ? t("Admin (temporary dev only)", "Administrador (temporal solo en desarrollo)")
                    : formatRoleLabel(role)}
                </option>
              ))}
            </select>
          </label>

          <button className="session-action" disabled={isCreating} type="submit">
            <AppIcon name="invite" />
            {isCreating ? t("Creating invite...", "Creando invitación...") : t("Create invite draft", "Crear borrador de invitación")}
          </button>
        </form>

        {canCreateTemporaryAdminInvites ? (
          <div className="login-feedback login-feedback--warning">
            {t("Temporary dev-only measure: admin invites are available in this dev build only and must be removed before production or staging promotion. ", "Medida temporal solo para desarrollo: las invitaciones de administrador están disponibles únicamente en esta compilación de desarrollo y deben eliminarse antes de promover a staging o producción. ")}</div>
        ) : null}

        <label className="login-field invite-issue-window">
          <span className="login-field__label">{t("Issue link duration (hours)", "Duración del enlace emitido (horas)")}</span>
          <input
            className="login-field__input"
            max={48}
            min={1}
            onChange={(event) => setIssueExpiryHours(event.target.value)}
            type="number"
            value={issueExpiryHours}
          />
        </label>

        {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}
        {success ? <div className="login-feedback login-feedback--success">{success}</div> : null}
      </section>

      {isLoading ? (
        <section className="workspace-panel">
          <p className="workspace-panel__copy">{t("Loading account invites...", "Cargando invitaciones de cuenta...")}</p>
        </section>
      ) : null}

      {!isLoading && invites.length === 0 ? (
        <section className="workspace-panel">
          <p className="workspace-panel__copy">
            {t("No invites have been created yet. ", "Todavía no se han creado invitaciones. ")}</p>
        </section>
      ) : null}

      {!isLoading && invites.length > 0 ? (
        <section className="invite-list">
          {invites.map((invite) => {
            const status = asInviteStatus(invite.status)
            const isBusy = activeInviteId === invite.id
            const canVerify = status === 'draft'
            const canIssue = status === 'verified' || status === 'expired'
            const canRevoke = status !== 'redeemed' && status !== 'revoked'
            const issuedLink = issuedLinkByInvite[invite.id]

            return (
              <article className="workspace-panel invite-card" key={invite.id}>
                <div className="audit-card__header">
                  <div>
                    <p className="workspace-sidebar__eyebrow">{t("Invite #", "Invitación #")}{invite.id}</p>
                    <h3 className="workspace-panel__title">{invite.email}</h3>
                  </div>
                  <span className={getStatusClassName(invite.status)}>
                    {formatStatusLabel(invite.status)}
                  </span>
                </div>

                <dl className="invite-meta-list" aria-label={t(`Invite ${invite.id} metadata`, `Metadatos de invitación ${invite.id}`)}>
                  <div className="invite-meta-list__item invite-meta-list__item--role">
                    <dt>{t("Role", "Rol")}</dt>
                    <dd><RoleBadge role={invite.role} /></dd>
                  </div>
                  <div className="invite-meta-list__item">
                    <dt>{t("Created", "Creada")}</dt>
                    <dd>{formatDateTime(invite.createdAt)}</dd>
                  </div>
                  <div className="invite-meta-list__item">
                    <dt>{t("Verified", "Verificada")}</dt>
                    <dd>{formatDateTime(invite.verifiedOutOfBandAt)}</dd>
                  </div>
                  <div className="invite-meta-list__item">
                    <dt>{t("Issued", "Emitida")}</dt>
                    <dd>{formatDateTime(invite.issuedAt)}</dd>
                  </div>
                  <div className="invite-meta-list__item">
                    <dt>{t("Expires", "Vence")}</dt>
                    <dd>{formatDateTime(invite.expiresAt)}</dd>
                  </div>
                  <div className="invite-meta-list__item">
                    <dt>{t("Redeemed", "Canjeada")}</dt>
                    <dd>{formatDateTime(invite.usedAt)}</dd>
                  </div>
                </dl>

                {canVerify ? (
                  <div className="invite-verify-form">
                    <label className="login-field">
                      <span className="login-field__label">{t("Verification method", "Método de verificación")}</span>
                      <select
                        className="login-field__input"
                        onChange={(event) =>
                          setVerificationMethodByInvite((current) => ({
                            ...current,
                            [invite.id]: event.target.value as InviteVerificationMethod,
                          }))
                        }
                        value={verificationMethodByInvite[invite.id] ?? 'phone'}
                      >
                        <option value="phone">{t("Phone", "Teléfono")}</option>
                        <option value="in_person">{t("In person", "En persona")}</option>
                      </select>
                    </label>

                    <label className="login-field">
                      <span className="login-field__label">{t("Verification note (optional)", "Nota de verificación (opcional)")}</span>
                      <input
                        className="login-field__input"
                        onChange={(event) =>
                          setVerificationNoteByInvite((current) => ({
                            ...current,
                            [invite.id]: event.target.value,
                          }))
                        }
                        placeholder={t("Reference for identity validation", "Referencia para validar identidad")}
                        type="text"
                        value={verificationNoteByInvite[invite.id] ?? ''}
                      />
                    </label>
                  </div>
                ) : null}

                <div className="session-actions invite-actions">
                  {canVerify ? (
                    <button
                      className="session-action"
                      disabled={isBusy}
                      onClick={() => handleRecordVerification(invite)}
                      type="button"
                    >
                      <AppIcon name="verify" />
                      {isBusy ? t("Recording...", "Registrando...") : t("Record verification", "Registrar verificación")}
                    </button>
                  ) : null}

                  {canIssue ? (
                    <button
                      className="session-action"
                      disabled={isBusy}
                      onClick={() => handleIssueLink(invite)}
                      type="button"
                    >
                      <AppIcon name="key" />
                      {isBusy ? t("Issuing...", "Emitiendo...") : t("Issue registration link", "Emitir enlace de registro")}
                    </button>
                  ) : null}

                  {canRevoke ? (
                    <button
                      className="session-action session-action--danger"
                      disabled={isBusy}
                      onClick={() => handleRevoke(invite)}
                      type="button"
                    >
                      <AppIcon name="delete" />
                      {isBusy ? t("Revoking...", "Revocando...") : t("Revoke invite", "Revocar invitación")}
                    </button>
                  ) : null}
                </div>

                {issuedLink ? (
                  <div className="invite-link-preview">
                    <p className="workspace-sidebar__eyebrow">{t("Issued registration link", "Enlace de registro emitido")}</p>
                    <code>{issuedLink}</code>
                    <button
                      className="session-action session-action--inline"
                      onClick={() => handleCopyIssuedLink(invite.id)}
                      type="button"
                    >
                      <AppIcon name="copy" />
                      {t("Copy link ", "Copiar enlace ")}</button>
                  </div>
                ) : null}
              </article>
            )
          })}
        </section>
      ) : null}
    </section>
  )
}
