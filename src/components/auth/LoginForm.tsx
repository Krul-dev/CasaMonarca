import { useState } from 'react'

import { login, type LoginCredentials } from '../../lib/auth'

type FormStatus = 'idle' | 'submitting' | 'error'

type FieldErrors = Partial<Record<keyof LoginCredentials, string>>

const validateCredentials = ({
  email,
  password,
}: LoginCredentials): FieldErrors => {
  const errors: FieldErrors = {}

  if (!email.trim()) {
    errors.email = 'Ingresa un correo institucional.'
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
    errors.email = 'Usa un correo con formato valido.'
  }

  if (!password.trim()) {
    errors.password = 'Ingresa tu contrasena.'
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
  const [feedbackMessage, setFeedbackMessage] = useState(
    'El flujo visual ya esta listo. Solo falta conectar la autenticacion real.',
  )

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

    if (formStatus === 'error') {
      setFormStatus('idle')
      setFeedbackMessage(
        'El flujo visual ya esta listo. Solo falta conectar la autenticacion real.',
      )
    }
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    const validationErrors = validateCredentials(credentials)

    if (Object.keys(validationErrors).length > 0) {
      setFieldErrors(validationErrors)
      setFormStatus('error')
      setFeedbackMessage('Corrige los campos marcados antes de continuar.')
      return
    }

    setFieldErrors({})
    setFormStatus('submitting')
    setFeedbackMessage('Probando el contrato del login...')

    try {
      await login(credentials)
    } catch (error) {
      setFormStatus('error')
      setFeedbackMessage(
        error instanceof Error
          ? error.message
          : 'No se pudo completar la autenticacion.',
      )
      return
    }

    setFormStatus('idle')
  }

  return (
    <form className="login-form" onSubmit={handleSubmit} noValidate>
      <div className="login-form__fields">
        <label className="login-field">
          <span className="login-field__label">Correo</span>
          <input
            autoComplete="username"
            className="login-field__input"
            name="email"
            placeholder="equipo@casamonarca.org.mx"
            type="email"
            value={credentials.email}
            onChange={(event) => handleFieldChange('email', event.target.value)}
          />
          {fieldErrors.email ? (
            <span className="login-field__error">{fieldErrors.email}</span>
          ) : null}
        </label>

        <label className="login-field">
          <span className="login-field__label">Contrasena</span>
          <input
            autoComplete="current-password"
            className="login-field__input"
            name="password"
            placeholder="Tu acceso institucional"
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
        {formStatus === 'submitting' ? 'Probando...' : 'Ingresar'}
      </button>

      <div
        className={`login-feedback ${
          formStatus === 'error'
            ? 'login-feedback--error'
            : 'login-feedback--neutral'
        }`}
      >
        {feedbackMessage}
      </div>
    </form>
  )
}
