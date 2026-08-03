import { translate as t } from '../lib/i18n'
import { useEffect, useState } from 'react'

import { AppIcon } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import { LOGIN_PATH } from '../config/appRoutes'
import { ApiRequestError } from '../lib/api'
import {
  previewInvite,
  redeemInvite,
  type InvitePreviewResponse,
  type InviteRedeemResponse,
} from '../lib/accountInvites'

type RegisterPageProps = {
  inviteTokenFromQuery: string | null
  loginPathForRegistration: (email: string) => string
  onNavigate: (to: string) => void
}

type RegisterForm = {
  email: string
  name: string
  password: string
  passwordConfirmation: string
  token: string
}

type FormStatus = 'idle' | 'submitting'
type InvitePreviewStatus = 'idle' | 'checking' | 'valid' | 'unavailable'

const initialForm = (token: string | null): RegisterForm => ({
  email: '',
  name: '',
  password: '',
  passwordConfirmation: '',
  token: token ?? '',
})

export function RegisterPage({
  inviteTokenFromQuery,
  loginPathForRegistration,
  onNavigate,
}: RegisterPageProps) {
  const [form, setForm] = useState<RegisterForm>(() => initialForm(inviteTokenFromQuery))
  const [status, setStatus] = useState<FormStatus>('idle')
  const [previewStatus, setPreviewStatus] = useState<InvitePreviewStatus>('idle')
  const [preview, setPreview] = useState<InvitePreviewResponse | null>(null)
  const [previewError, setPreviewError] = useState<string | null>(null)
  const [previewWarning, setPreviewWarning] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<InviteRedeemResponse | null>(null)
  const successfulLoginPath = success
    ? loginPathForRegistration(success.user.email)
    : LOGIN_PATH

  useEffect(() => {
    const queryToken = inviteTokenFromQuery?.trim() ?? ''

    setForm((current) => ({
      ...current,
      token: queryToken || current.token,
    }))

    if (!queryToken) {
      setPreview(null)
      setPreviewError(null)
      setPreviewWarning(null)
      setPreviewStatus('idle')
      return
    }

    let isCurrent = true

    setPreviewStatus('checking')
    setPreview(null)
    setPreviewError(null)
    setPreviewWarning(null)
    setError(null)

    previewInvite(queryToken)
      .then((response) => {
        if (!isCurrent) {
          return
        }

        setPreview(response)
        setPreviewStatus('valid')
        setForm((current) => ({
          ...current,
          email: response.invite.email,
          token: queryToken,
        }))
      })
      .catch((previewError) => {
        if (!isCurrent) {
          return
        }

        if (previewError instanceof ApiRequestError && previewError.status === 404) {
          setPreview(null)
          setPreviewError(null)
          setPreviewWarning('Invite pre-check is not available on this server yet. Complete the form to redeem the invite.')
          setPreviewStatus('idle')
          return
        }

        setPreview(null)
        setPreviewWarning(null)
        setPreviewStatus('unavailable')
        setPreviewError(
          previewError instanceof ApiRequestError || previewError instanceof Error
            ? previewError.message
            : 'Invite link is no longer available.',
        )
      })

    return () => {
      isCurrent = false
    }
  }, [inviteTokenFromQuery])

  const setField = <K extends keyof RegisterForm>(field: K, value: RegisterForm[K]) => {
    setForm((current) => ({
      ...current,
      [field]: value,
    }))
  }

  const validate = (): string | null => {
    if (!form.token.trim()) {
      return t("Invite token is required.", "El token de invitación es obligatorio.")
    }

    if (!form.name.trim()) {
      return t("Name is required.", "El nombre es obligatorio.")
    }

    if (!form.email.trim()) {
      return t("Email is required.", "El correo electrónico es obligatorio.")
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
      return t("Use a valid email format.", "Usa un formato de correo electrónico válido.")
    }

    if (form.password.length < 8) {
      return t("Password must contain at least 8 characters.", "La contraseña debe tener al menos 8 caracteres.")
    }

    if (form.password !== form.passwordConfirmation) {
      return t("Password confirmation does not match.", "La confirmación de contraseña no coincide.")
    }

    return null
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const validationMessage = validate()

    if (validationMessage) {
      setError(validationMessage)
      setSuccess(null)
      return
    }

    setStatus('submitting')
    setError(null)
    setSuccess(null)

    try {
      const response = await redeemInvite({
        token: form.token.trim(),
        name: form.name.trim(),
        email: form.email.trim().toLowerCase(),
        password: form.password,
        password_confirmation: form.passwordConfirmation,
      })

      setSuccess(response)
    } catch (submitError) {
      setError(
        submitError instanceof ApiRequestError || submitError instanceof Error
          ? submitError.message
          : t("Registration could not be completed.", "No se pudo completar el registro."),
      )
    } finally {
      setStatus('idle')
    }
  }

  return (
    <main className="route-shell">
      <section className="login-layout login-layout--single">
        <section className="login-panel" aria-labelledby="register-panel-title">
          <div className="login-panel__header">
            <p className="login-panel__eyebrow">{t("Invite registration", "Registro por invitación")}</p>
            <h2 className="login-panel__title" id="register-panel-title">
              {t("Create your account ", "Crea tu cuenta ")}</h2>
            <p className="workspace-panel__copy">
              {t("Complete registration with the role and email assigned in the invite. ", "Completa el registro con el rol y el correo asignados en la invitación. ")}</p>
          </div>

          {previewStatus === 'checking' ? (
            <div className="login-feedback login-feedback--warning">
              {t("Checking invite link before showing the registration form... ", "Verificando el enlace de invitación antes de mostrar el formulario de registro... ")}</div>
          ) : null}

          {previewWarning ? (
            <div className="login-feedback login-feedback--warning">
              {previewWarning}
            </div>
          ) : null}

          {previewStatus === 'unavailable' ? (
            <div className="login-feedback login-feedback--error">
              <p>{previewError ?? t("Invite link is no longer available.", "El enlace de invitación ya no está disponible.")}</p>
              <p>{t("Ask an administrator or coordinator to issue a new registration link.", "Pide a una persona administradora o coordinadora que emita un nuevo enlace de registro.")}</p>
            </div>
          ) : null}

          {preview ? (
            <div className="login-feedback login-feedback--success">
              <p>{preview.message}</p>
              <p>
                {t("Assigned account: ", "Cuenta asignada: ")}{preview.invite.email} · <RoleBadge role={preview.invite.role} />
              </p>
            </div>
          ) : null}

          {previewStatus !== 'checking' && previewStatus !== 'unavailable' ? (
            <form className="login-form" noValidate onSubmit={handleSubmit}>
              <div className="login-form__fields">
                <label className="login-field">
                    <span className="login-field__label">{t("Invite token", "Token de invitación")}</span>
                  <input
                    className="login-field__input"
                    onChange={(event) => setField('token', event.target.value)}
                    placeholder={t("Paste invite token", "Pega el token de invitación")}
                    readOnly={Boolean(inviteTokenFromQuery?.trim())}
                    type="text"
                    value={form.token}
                  />
                </label>

                <label className="login-field">
                  <span className="login-field__label">{t("Name", "Nombre")}</span>
                  <input
                    className="login-field__input"
                    onChange={(event) => setField('name', event.target.value)}
                    placeholder={t("Your full name", "Tu nombre completo")}
                    type="text"
                    value={form.name}
                  />
                </label>

                <label className="login-field">
                  <span className="login-field__label">{t("Email", "Correo electrónico")}</span>
                  <input
                    className="login-field__input"
                    onChange={(event) => setField('email', event.target.value)}
                    placeholder={t("assigned.email@casamonarca.local", "correo.asignado@casamonarca.local")}
                    readOnly={previewStatus === 'valid'}
                    type="email"
                    value={form.email}
                  />
                </label>

                <label className="login-field">
                  <span className="login-field__label">{t("Password", "Contraseña")}</span>
                  <input
                    className="login-field__input"
                    onChange={(event) => setField('password', event.target.value)}
                    placeholder={t("Set a strong password", "Define una contraseña segura")}
                    type="password"
                    value={form.password}
                  />
                </label>

                <label className="login-field">
                  <span className="login-field__label">{t("Confirm password", "Confirma la contraseña")}</span>
                  <input
                    className="login-field__input"
                    onChange={(event) => setField('passwordConfirmation', event.target.value)}
                    placeholder={t("Repeat password", "Repite la contraseña")}
                    type="password"
                    value={form.passwordConfirmation}
                  />
                </label>
              </div>

              <button className="login-submit" disabled={status === 'submitting'} type="submit">
                <AppIcon name="invite" />
                {status === 'submitting' ? t("Creating account...", "Creando cuenta...") : t("Redeem invite", "Canjear invitación")}
              </button>
            </form>
          ) : null}

          {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

          {success ? (
            <div className="login-feedback login-feedback--success">
              <p>{success.message}</p>
              <p>
                {t("Account role: ", "Rol de la cuenta: ")}<RoleBadge role={success.user.role} />
              </p>
              <p>
                {t("Required enrollment: ", "Inscripción requerida: ")}{' '}
                TOTP {success.enrollment.requiresTotp ? t("required", "obligatorio") : t("optional", "opcional")}
                {' · '}
                {t("Passkey ", "Llave de acceso ")}{success.enrollment.requiresPasskey ? t("required", "obligatoria") : t("optional", "opcional")}
              </p>
            </div>
          ) : null}

          <button
            className="session-action"
            onClick={() => onNavigate(successfulLoginPath)}
            type="button"
          >
            <AppIcon name="login" />
            {success ? t("Continue to sign in", "Continuar al inicio de sesión") : t("Go to sign in", "Ir al inicio de sesión")}
          </button>
        </section>
      </section>
    </main>
  )
}
