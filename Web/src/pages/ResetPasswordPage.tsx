import { useEffect, useState } from 'react'

import { AppIcon } from '../components/ui/AppIcon'
import { LOGIN_PATH } from '../config/appRoutes'
import { ApiRequestError } from '../lib/api'
import { completePasswordReset } from '../lib/auth'

type ResetPasswordPageProps = {
  emailFromQuery: string | null
  loginPathForReset: (email: string) => string
  onNavigate: (to: string) => void
  tokenFromQuery: string | null
}

type ResetPasswordForm = {
  email: string
  password: string
  passwordConfirmation: string
  token: string
}

type FormStatus = 'idle' | 'submitting'

const initialForm = (
  email: string | null,
  token: string | null,
): ResetPasswordForm => ({
  email: email ?? '',
  password: '',
  passwordConfirmation: '',
  token: token ?? '',
})

export function ResetPasswordPage({
  emailFromQuery,
  loginPathForReset,
  onNavigate,
  tokenFromQuery,
}: ResetPasswordPageProps) {
  const [form, setForm] = useState<ResetPasswordForm>(() => initialForm(emailFromQuery, tokenFromQuery))
  const [status, setStatus] = useState<FormStatus>('idle')
  const [error, setError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const successfulLoginPath = successMessage
    ? loginPathForReset(form.email.trim().toLowerCase())
    : LOGIN_PATH

  useEffect(() => {
    setForm((current) => ({
      ...current,
      email: emailFromQuery ?? current.email,
      token: tokenFromQuery ?? current.token,
    }))
  }, [emailFromQuery, tokenFromQuery])

  const setField = <K extends keyof ResetPasswordForm>(field: K, value: ResetPasswordForm[K]) => {
    setForm((current) => ({
      ...current,
      [field]: value,
    }))
  }

  const validate = (): string | null => {
    if (!form.token.trim()) {
      return 'El token para restablecer contraseña es obligatorio.'
    }

    if (!form.email.trim()) {
      return 'El correo electrónico es obligatorio.'
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
      return 'Usa un formato de correo electrónico válido.'
    }

    if (form.password.length < 8) {
      return 'La contraseña debe tener al menos 8 caracteres.'
    }

    if (form.password !== form.passwordConfirmation) {
      return 'La confirmación de contraseña no coincide.'
    }

    return null
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const validationMessage = validate()

    if (validationMessage) {
      setError(validationMessage)
      setSuccessMessage(null)
      return
    }

    setStatus('submitting')
    setError(null)
    setSuccessMessage(null)

    try {
      const response = await completePasswordReset({
        email: form.email.trim().toLowerCase(),
        token: form.token.trim(),
        password: form.password,
        password_confirmation: form.passwordConfirmation,
      })

      setSuccessMessage(response.message)
      setForm((current) => ({
        ...current,
        password: '',
        passwordConfirmation: '',
      }))
    } catch (submitError) {
      setError(
        submitError instanceof ApiRequestError || submitError instanceof Error
          ? submitError.message
          : 'No se pudo restablecer la contraseña.',
      )
    } finally {
      setStatus('idle')
    }
  }

  return (
    <main className="route-shell">
      <section className="login-layout login-layout--single">
        <section className="login-panel" aria-labelledby="reset-password-panel-title">
          <div className="login-panel__header">
            <p className="login-panel__eyebrow">Recuperación de cuenta</p>
            <h2 className="login-panel__title" id="reset-password-panel-title">
              Restablece tu contraseña
            </h2>
            <p className="workspace-panel__copy">
              Usa el enlace de recuperación emitido por administración para definir una nueva contraseña. El token es de un solo uso y vence pronto.
            </p>
          </div>

          <form className="login-form" noValidate onSubmit={handleSubmit}>
            <div className="login-form__fields">
              <label className="login-field">
                <span className="login-field__label">Token de restablecimiento</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('token', event.target.value)}
                  placeholder="Pega el token de restablecimiento"
                  type="text"
                  value={form.token}
                />
              </label>

              <label className="login-field">
                <span className="login-field__label">Correo electrónico</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('email', event.target.value)}
                  placeholder="tu.correo@casamonarca.local"
                  type="email"
                  value={form.email}
                />
              </label>

              <label className="login-field">
                <span className="login-field__label">Nueva contraseña</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('password', event.target.value)}
                  placeholder="Define una nueva contraseña"
                  type="password"
                  value={form.password}
                />
              </label>

              <label className="login-field">
                <span className="login-field__label">Confirma la nueva contraseña</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('passwordConfirmation', event.target.value)}
                  placeholder="Repite la nueva contraseña"
                  type="password"
                  value={form.passwordConfirmation}
                />
              </label>
            </div>

            <button className="login-submit" disabled={status === 'submitting'} type="submit">
              <AppIcon name="key" />
              {status === 'submitting' ? 'Restableciendo contraseña...' : 'Restablecer contraseña'}
            </button>
          </form>

          {error ? <div className="login-feedback login-feedback--error">{error}</div> : null}

          {successMessage ? (
            <div className="login-feedback login-feedback--success">
              <p>{successMessage}</p>
            </div>
          ) : null}

          <button
            className="session-action"
            onClick={() => onNavigate(successfulLoginPath)}
            type="button"
          >
            <AppIcon name="login" />
            {successMessage ? 'Continuar al inicio de sesión' : 'Ir al inicio de sesión'}
          </button>
        </section>
      </section>
    </main>
  )
}
