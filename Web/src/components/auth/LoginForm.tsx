import { useState } from 'react'

import { AppIcon } from '../ui/AppIcon'
import { ApiRequestError } from '../../lib/api'
import {
  completeTotpLogin,
  getFirstFieldError,
  login,
  startWebauthnLogin,
  verifyWebauthnLogin,
  type AuthenticatedUser,
  type LoginCredentials,
  type TotpCredentials,
} from '../../lib/auth'

type FormStatus = 'idle' | 'submitting'
type AuthenticationStep = 'credentials' | 'totp'
type FormFieldName = keyof (LoginCredentials & TotpCredentials)

type FieldErrors = Partial<Record<FormFieldName, string>>

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

const validateTotpCredentials = ({ code }: TotpCredentials): FieldErrors => {
  const errors: FieldErrors = {}

  if (!code.trim()) {
    errors.code = 'Enter your 6-digit authentication code.'
  } else if (!/^\d{6}$/.test(code.trim())) {
    errors.code = 'Use a valid 6-digit authentication code.'
  }

  return errors
}

const base64UrlToArrayBuffer = (value: string): ArrayBuffer => {
  const padding = '='.repeat((4 - (value.length % 4)) % 4)
  const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(base64)
  const bytes = new Uint8Array(raw.length)

  for (let index = 0; index < raw.length; index += 1) {
    bytes[index] = raw.charCodeAt(index)
  }

  return bytes.buffer
}

const arrayBufferToBase64Url = (buffer: ArrayBuffer): string => {
  const bytes = new Uint8Array(buffer)
  let binary = ''

  bytes.forEach((byte) => {
    binary += String.fromCharCode(byte)
  })

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

const isIpHostname = (hostname: string): boolean => {
  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(hostname)) {
    return true
  }

  return hostname.includes(':')
}

type LoginFormProps = {
  initialEmail?: string
  onAuthenticated?: (user: AuthenticatedUser) => void
}

export function LoginForm({
  initialEmail = '',
  onAuthenticated,
}: LoginFormProps) {
  const [credentials, setCredentials] = useState<LoginCredentials>({
    email: initialEmail,
    password: '',
  })
  const [totpCode, setTotpCode] = useState('')
  const [authenticationStep, setAuthenticationStep] =
    useState<AuthenticationStep>('credentials')
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>({})
  const [formStatus, setFormStatus] = useState<FormStatus>('idle')
  const [submitError, setSubmitError] = useState<string | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState<string | null>(null)

  const clearFeedbackMessages = () => {
    if (submitError) {
      setSubmitError(null)
    }

    if (submitSuccess) {
      setSubmitSuccess(null)
    }
  }

  const handleCredentialsChange = (field: keyof LoginCredentials, value: string) => {
    setCredentials((current) => ({
      ...current,
      [field]: value,
    }))

    setFieldErrors((current) => ({
      ...current,
      [field]: undefined,
    }))

    clearFeedbackMessages()
  }

  const handleTotpCodeChange = (value: string) => {
    setTotpCode(value)

    setFieldErrors((current) => ({
      ...current,
      code: undefined,
    }))

    clearFeedbackMessages()
  }

  const handleCredentialsSubmit = async () => {
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
      setFormStatus('idle')

      if (response.requiresTwoFactor) {
        setAuthenticationStep('totp')
        setSubmitSuccess(response.message)
        return
      }

      if (onAuthenticated) {
        onAuthenticated(response.user)
        return
      }

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
    }
  }

  const handleTotpSubmit = async () => {
    const validationErrors = validateTotpCredentials({ code: totpCode })

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
      const response = await completeTotpLogin({
        code: totpCode.trim(),
      })
      setFormStatus('idle')

      if (onAuthenticated) {
        onAuthenticated(response.user)
        return
      }

      setSubmitSuccess(
        response.user.name
          ? `Login successful. Signed in as ${response.user.name}.`
          : response.message,
      )
    } catch (error) {
      if (error instanceof ApiRequestError) {
        if (error.status === 401) {
          setAuthenticationStep('credentials')
          setTotpCode('')
          setFieldErrors({})
        } else {
          setFieldErrors((current) => ({
            ...current,
            code: getFirstFieldError(error.errors, 'code'),
          }))
        }
      }

      setSubmitError(
        error instanceof Error
          ? error.message
          : 'The authentication request could not be completed.',
      )
      setFormStatus('idle')
    }
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault()

    if (authenticationStep === 'credentials') {
      await handleCredentialsSubmit()
      return
    }

    await handleTotpSubmit()
  }

  const handleWebauthnLogin = async () => {
    if (
      !window.isSecureContext ||
      !('PublicKeyCredential' in window) ||
      !('credentials' in navigator)
    ) {
      setSubmitError(
        'WebAuthn sign-in is only available in a secure context and supported browser.',
      )
      setSubmitSuccess(null)
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setSubmitError(
        'WebAuthn sign-in requires localhost or a domain name. Open this app from localhost or your staging domain.',
      )
      setSubmitSuccess(null)
      return
    }

    setFieldErrors({})
    setSubmitError(null)
    setSubmitSuccess(null)
    setFormStatus('submitting')

    try {
      const optionsResponse = await startWebauthnLogin(
        credentials.email.trim() || undefined,
      )
      const options = optionsResponse.options

      const credential = await navigator.credentials.get({
        publicKey: {
          ...options,
          challenge: base64UrlToArrayBuffer(options.challenge),
          allowCredentials: options.allowCredentials?.map((credentialOption) => ({
            ...credentialOption,
            id: base64UrlToArrayBuffer(credentialOption.id),
            transports: credentialOption.transports ?? undefined,
          })),
        },
      })

      if (!(credential instanceof PublicKeyCredential)) {
        throw new Error('The security key did not return a valid WebAuthn assertion.')
      }

      const response = credential.response

      if (!(response instanceof AuthenticatorAssertionResponse)) {
        throw new Error('WebAuthn assertion response is invalid for sign-in.')
      }

      const loginResponse = await verifyWebauthnLogin({
        id: credential.id,
        rawId: arrayBufferToBase64Url(credential.rawId),
        type: 'public-key',
        response: {
          authenticatorData: arrayBufferToBase64Url(response.authenticatorData),
          clientDataJSON: arrayBufferToBase64Url(response.clientDataJSON),
          signature: arrayBufferToBase64Url(response.signature),
          userHandle: response.userHandle
            ? arrayBufferToBase64Url(response.userHandle)
            : undefined,
        },
      })

      setFormStatus('idle')

      if (onAuthenticated) {
        onAuthenticated(loginResponse.user)
        return
      }

      setSubmitSuccess(
        loginResponse.user.name
          ? `Login successful. Signed in as ${loginResponse.user.name}.`
          : loginResponse.message,
      )
    } catch (error) {
      const message =
        error instanceof Error
          ? error.name === 'NotAllowedError'
            ? 'Security key sign-in was cancelled.'
            : error.message
          : 'The WebAuthn sign-in request could not be completed.'

      setSubmitError(message)
      setFormStatus('idle')
    }
  }

  const submitButtonLabel =
    authenticationStep === 'credentials' ? 'Sign in' : 'Verify code'

  return (
    <form className="login-form" onSubmit={handleSubmit} noValidate>
      <div className="login-form__fields">
        {authenticationStep === 'credentials' ? (
          <>
            <label className="login-field">
              <span className="login-field__label">Email</span>
              <input
                autoComplete="username"
                className="login-field__input"
                name="email"
                placeholder="team@casamonarca.org.mx"
                type="email"
                value={credentials.email}
                onChange={(event) =>
                  handleCredentialsChange('email', event.target.value)
                }
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
                  handleCredentialsChange('password', event.target.value)
                }
              />
              {fieldErrors.password ? (
                <span className="login-field__error">{fieldErrors.password}</span>
              ) : null}
            </label>
          </>
        ) : (
          <>
            <div className="route-panel" aria-label="Two-factor challenge">
              <h3 className="route-panel__title">Two-factor verification</h3>
              <ul className="route-checklist">
                <li>Open your authenticator app for this account.</li>
                <li>Enter the current 6-digit TOTP code below.</li>
              </ul>
            </div>

            <label className="login-field">
              <span className="login-field__label">Authentication code</span>
              <input
                autoComplete="one-time-code"
                className="login-field__input"
                inputMode="numeric"
                maxLength={6}
                name="code"
                placeholder="123456"
                type="text"
                value={totpCode}
                onChange={(event) => handleTotpCodeChange(event.target.value)}
              />
              {fieldErrors.code ? (
                <span className="login-field__error">{fieldErrors.code}</span>
              ) : null}
            </label>
          </>
        )}
      </div>

      <button
        className="login-submit"
        disabled={formStatus === 'submitting'}
        type="submit"
      >
        <AppIcon name="login" />
        {formStatus === 'submitting' ? 'Testing...' : submitButtonLabel}
      </button>

      {authenticationStep === 'credentials' ? (
        <button
          className="login-submit login-submit--secondary"
          disabled={formStatus === 'submitting'}
          onClick={handleWebauthnLogin}
          type="button"
        >
          <AppIcon name="key" />
          Sign in with security key
        </button>
      ) : null}

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
