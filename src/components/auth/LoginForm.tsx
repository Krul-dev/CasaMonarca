import { useState } from 'react'

import { ApiRequestError } from '../../lib/api'
import {
  getFirstFieldError,
  login,
  type LoginCredentials,
} from '../../lib/auth'

type FormStatus = 'idle' | 'submitting'

type FieldErrors = Partial<Record<keyof LoginCredentials, string>>

const validateCredentials = ({
  email,
  password,
}: LoginCredentials): FieldErrors => {
  const errors: FieldErrors = {}

  if (!email.trim()) {
    errors.email = 'Enter your institutional email.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = 'Use a valid email format.'
  }

  if (!password.trim()) {
    errors.password = 'Enter your password.'
  }

  return errors
}

export function LoginForm() {
  const [credentials, setCredentials] = useState<LoginCredentials>({
    email: '',
    password: '',
  })
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [formStatus, setFormStatus] = useState<FormStatus>('idle')
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState<string | null>(null)

  const handleFieldChange = (
    field: keyof LoginCredentials,
    value: string,
  ) => {
    setCredentials((current) => ({
      ...current,
      [field]: value,
    }))

    setFieldErrors((current) => ({
      ...current,
      [field]: undefined,
    }))

    if (submitError) {
      setSubmitError(null)
    }

    if (submitSuccess) {
      setSubmitSuccess(null)
    }
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const validationErrors = validateCredentials(credentials)

    if (Object.keys(validationErrors).length > 0) {
      setFieldErrors(validationErrors)
      setSubmitError(null)
      setSubmitSuccess(null)
      return
    }

    setFieldErrors({})
    setSubmitError(null)
    setSubmitSuccess(null)
    setFormStatus('submitting')

    try {
      const response = await login(credentials)
      setSubmitSuccess(
        response.user.name
          ? `Login successful. Signed in as ${response.user.name}.`
          : response.message,
      )
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setFieldErrors((current) => ({
          ...current,
          email: getFirstFieldError(error.errors, 'email'),
          password: getFirstFieldError(error.errors, 'password'),
        }))
      }

      setSubmitError(
        error instanceof Error
          ? error.message
          : 'The authentication request could not be completed.',
      )
      setFormStatus('idle')
      return
    }

    setFormStatus('idle')
  }

  return (
    <form className="login-form" onSubmit={handleSubmit} noValidate>
      <div className="login-form__fields">
        <label className="login-field">
          <span className="login-field__label">Email</span>
          <input
            autoComplete="username"
            className="login-field__input"
            name="email"
            placeholder="team@casamonarca.org.mx"
            type="email"
            value={credentials.email}
            onChange={(event) => handleFieldChange('email', event.target.value)}
          />
          {fieldErrors.email ? (
            <span className="login-field__error">{fieldErrors.email}</span>
          ) : null}
        </label>

        <label className="login-field">
          <span className="login-field__label">Password</span>
          <input
            autoComplete="current-password"
            className="login-field__input"
            name="password"
            placeholder="Your institutional access"
            type="password"
            value={credentials.password}
            onChange={(event) =>
              handleFieldChange('password', event.target.value)
            }
          />
          {fieldErrors.password ? (
            <span className="login-field__error">{fieldErrors.password}</span>
          ) : null}
        </label>
      </div>

      <button
        className="login-submit"
        disabled={formStatus === 'submitting'}
        type="submit"
      >
        {formStatus === 'submitting' ? 'Testing...' : 'Sign in'}
      </button>

      {submitSuccess ? (
        <div className="login-feedback login-feedback--success">
          {submitSuccess}
        </div>
      ) : null}

      {submitError ? (
        <div className="login-feedback login-feedback--error">{submitError}</div>
      ) : null}
    </form>
  )
}
