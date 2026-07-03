import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ApiRequestError } from '../../lib/api'
import * as auth from '../../lib/auth'
import { LoginForm } from './LoginForm'

vi.mock('../../lib/auth', async () => {
  const actual = await vi.importActual<typeof import('../../lib/auth')>(
    '../../lib/auth',
  )

  return {
    ...actual,
    completeTotpLogin: vi.fn(),
    login: vi.fn(),
    startWebauthnLogin: vi.fn(),
    verifyWebauthnLogin: vi.fn(),
  }
})

const loginMock = vi.mocked(auth.login)
const completeTotpLoginMock = vi.mocked(auth.completeTotpLogin)
const startWebauthnLoginMock = vi.mocked(auth.startWebauthnLogin)
const verifyWebauthnLoginMock = vi.mocked(auth.verifyWebauthnLogin)

const credentials = {
  email: 'admin@casamonarca.org.mx',
  password: 'StrongPass#123',
}

const user = {
  capabilities: {
    modules: {
      admin: true,
      dashboard: true,
      documents: true,
      history: true,
      invites: true,
      logging: true,
      upload: true,
    },
    security: {
      enrolled: {
        passkey: true,
        totp: true,
      },
      enforced: false,
      isFullyEnrolled: true,
      missing: {
        passkey: false,
        totp: false,
      },
      requires: {
        passkey: false,
        totp: false,
      },
    },
  },
  id: 1,
  email: credentials.email,
  name: 'Admin User',
  role: 'admin' as const,
}

const loginButtonLabel = 'Sign in'

describe('LoginForm', () => {
  afterEach(() => {
    vi.unstubAllGlobals()
  })

  beforeEach(() => {
    loginMock.mockReset()
    completeTotpLoginMock.mockReset()
    startWebauthnLoginMock.mockReset()
    verifyWebauthnLoginMock.mockReset()
  })

  it('validates required credentials before sending a request', async () => {
    const userInteraction = userEvent.setup()

    render(<LoginForm />)

    await userInteraction.click(
      screen.getByRole('button', { name: loginButtonLabel }),
    )

    expect(
      await screen.findByText('Enter your institutional email.'),
    ).toBeInTheDocument()
    expect(await screen.findByText('Enter your password.')).toBeInTheDocument()
    expect(loginMock).not.toHaveBeenCalled()
  })

  it('shows loading state while waiting for login response', async () => {
    const userInteraction = userEvent.setup()
    let resolveLogin: ((value: auth.LoginResponse) => void) | undefined

    const pendingLogin = new Promise<auth.LoginResponse>((resolve) => {
      resolveLogin = resolve
    })

    loginMock.mockReturnValueOnce(pendingLogin)

    render(<LoginForm />)

    await userInteraction.type(screen.getByLabelText('Email'), credentials.email)
    await userInteraction.type(
      screen.getByLabelText('Password'),
      credentials.password,
    )
    await userInteraction.click(
      screen.getByRole('button', { name: loginButtonLabel }),
    )

    const submitButton = screen.getByRole('button', { name: 'Testing...' })
    expect(submitButton).toBeDisabled()

    resolveLogin?.({
      message: 'Logged in',
      requiresTwoFactor: false,
      user,
    })

    await waitFor(() =>
      expect(
        screen.getByRole('button', { name: loginButtonLabel }),
      ).toBeEnabled(),
    )
  })

  it('maps backend field errors to the form', async () => {
    const userInteraction = userEvent.setup()

    loginMock.mockRejectedValueOnce(
      new ApiRequestError('Validation failed.', 422, {
        email: ['Email is required by backend.'],
        password: ['Password is required by backend.'],
      }),
    )

    render(<LoginForm />)

    await userInteraction.type(screen.getByLabelText('Email'), credentials.email)
    await userInteraction.type(
      screen.getByLabelText('Password'),
      credentials.password,
    )
    await userInteraction.click(
      screen.getByRole('button', { name: loginButtonLabel }),
    )

    expect(
      await screen.findByText('Email is required by backend.'),
    ).toBeInTheDocument()
    expect(
      await screen.findByText('Password is required by backend.'),
    ).toBeInTheDocument()
    expect(await screen.findByText('Validation failed.')).toBeInTheDocument()
  })

  it('calls onAuthenticated with the backend user on successful login', async () => {
    const userInteraction = userEvent.setup()
    const onAuthenticated = vi.fn()

    loginMock.mockResolvedValueOnce({
      message: 'Logged in',
      requiresTwoFactor: false,
      user,
    })

    render(<LoginForm onAuthenticated={onAuthenticated} />)

    await userInteraction.type(screen.getByLabelText('Email'), credentials.email)
    await userInteraction.type(
      screen.getByLabelText('Password'),
      credentials.password,
    )
    await userInteraction.click(
      screen.getByRole('button', { name: loginButtonLabel }),
    )

    await waitFor(() => {
      expect(onAuthenticated).toHaveBeenCalledTimes(1)
      expect(onAuthenticated).toHaveBeenCalledWith(user)
    })
  })

  it('handles a TOTP challenge and completes login with verification code', async () => {
    const userInteraction = userEvent.setup()
    const onAuthenticated = vi.fn()

    loginMock.mockResolvedValueOnce({
      message: 'Two-factor authentication code is required.',
      requiresTwoFactor: true,
    })
    completeTotpLoginMock.mockResolvedValueOnce({
      message: 'Logged in',
      requiresTwoFactor: false,
      user,
    })

    render(<LoginForm onAuthenticated={onAuthenticated} />)

    await userInteraction.type(screen.getByLabelText('Email'), credentials.email)
    await userInteraction.type(
      screen.getByLabelText('Password'),
      credentials.password,
    )
    await userInteraction.click(
      screen.getByRole('button', { name: loginButtonLabel }),
    )

    expect(await screen.findByText('Two-factor verification')).toBeInTheDocument()
    expect(
      await screen.findByText('Two-factor authentication code is required.'),
    ).toBeInTheDocument()

    await userInteraction.type(
      screen.getByLabelText('Authentication code'),
      '123456',
    )
    await userInteraction.click(
      screen.getByRole('button', { name: 'Verify code' }),
    )

    await waitFor(() => {
      expect(completeTotpLoginMock).toHaveBeenCalledTimes(1)
      expect(completeTotpLoginMock).toHaveBeenCalledWith({
        code: '123456',
      })
      expect(onAuthenticated).toHaveBeenCalledWith(user)
    })
  })

  it('signs in with a registered security key', async () => {
    const userInteraction = userEvent.setup()
    const onAuthenticated = vi.fn()

    class MockAuthenticatorAssertionResponse {
      readonly authenticatorData = new Uint8Array([1, 2, 3]).buffer
      readonly clientDataJSON = new Uint8Array([4, 5, 6]).buffer
      readonly signature = new Uint8Array([7, 8, 9]).buffer
      readonly userHandle = null
    }

    class MockPublicKeyCredential {
      readonly id = 'credential-id'
      readonly rawId = new Uint8Array([10, 11, 12]).buffer
      readonly response = new MockAuthenticatorAssertionResponse()
      readonly type = 'public-key'
    }

    Object.defineProperty(window, 'isSecureContext', {
      configurable: true,
      value: true,
    })
    vi.stubGlobal('PublicKeyCredential', MockPublicKeyCredential)
    vi.stubGlobal(
      'AuthenticatorAssertionResponse',
      MockAuthenticatorAssertionResponse,
    )

    const credentialsGetMock = vi
      .fn()
      .mockResolvedValue(new MockPublicKeyCredential())

    Object.defineProperty(navigator, 'credentials', {
      configurable: true,
      value: {
        get: credentialsGetMock,
      },
    })

    startWebauthnLoginMock.mockResolvedValueOnce({
      message: 'WebAuthn login challenge created.',
      options: {
        challenge: 'AQIDBA',
        rpId: 'localhost',
        allowCredentials: [
          {
            id: 'AQIDBAUG',
            type: 'public-key',
            transports: ['usb'],
          },
        ],
      },
    })

    verifyWebauthnLoginMock.mockResolvedValueOnce({
      message: 'Login successful.',
      requiresTwoFactor: false,
      user,
    })

    render(<LoginForm onAuthenticated={onAuthenticated} />)

    await userInteraction.type(screen.getByLabelText('Email'), credentials.email)
    await userInteraction.click(
      screen.getByRole('button', { name: 'Sign in with security key' }),
    )

    await waitFor(() => {
      expect(startWebauthnLoginMock).toHaveBeenCalledWith(credentials.email)
      expect(credentialsGetMock).toHaveBeenCalledTimes(1)
      expect(verifyWebauthnLoginMock).toHaveBeenCalledTimes(1)
      expect(onAuthenticated).toHaveBeenCalledWith(user)
    })
  })
})
