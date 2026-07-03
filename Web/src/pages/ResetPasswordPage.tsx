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
      return 'Password reset token is required.'
    }

    if (!form.email.trim()) {
      return 'Email is required.'
    }

    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
      return 'Use a valid email format.'
    }

    if (form.password.length < 8) {
      return 'Password must contain at least 8 characters.'
    }

    if (form.password !== form.passwordConfirmation) {
      return 'Password confirmation does not match.'
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
          : 'Password reset could not be completed.',
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
            <p className="login-panel__eyebrow">Account recovery</p>
            <h2 className="login-panel__title" id="reset-password-panel-title">
              Reset your password
            </h2>
            <p className="workspace-panel__copy">
              Use the admin-issued recovery link to set a new password. The token is single-use and expires quickly.
            </p>
          </div>

          <form className="login-form" noValidate onSubmit={handleSubmit}>
            <div className="login-form__fields">
              <label className="login-field">
                <span className="login-field__label">Reset token</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('token', event.target.value)}
                  placeholder="Paste reset token"
                  type="text"
                  value={form.token}
                />
              </label>

              <label className="login-field">
                <span className="login-field__label">Email</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('email', event.target.value)}
                  placeholder="your.email@casamonarca.local"
                  type="email"
                  value={form.email}
                />
              </label>

              <label className="login-field">
                <span className="login-field__label">New password</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('password', event.target.value)}
                  placeholder="Set a new password"
                  type="password"
                  value={form.password}
                />
              </label>

              <label className="login-field">
                <span className="login-field__label">Confirm new password</span>
                <input
                  className="login-field__input"
                  onChange={(event) => setField('passwordConfirmation', event.target.value)}
                  placeholder="Repeat new password"
                  type="password"
                  value={form.passwordConfirmation}
                />
              </label>
            </div>

            <button className="login-submit" disabled={status === 'submitting'} type="submit">
              <AppIcon name="key" />
              {status === 'submitting' ? 'Resetting password...' : 'Reset password'}
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
            {successMessage ? 'Continue to sign in' : 'Go to sign in'}
          </button>
        </section>
      </section>
    </main>
  )
}
