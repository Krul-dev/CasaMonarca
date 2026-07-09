import { useCallback, useEffect, useRef, useState } from 'react'
import { toDataURL } from 'qrcode'

import { AppIcon } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import { APP_DOCUMENTS_PATH, APP_MIGRANT_APPROVALS_PATH } from '../config/appRoutes'
import { ApiRequestError } from '../lib/api'
import {
  getCurrentUser,
  getWebauthnCredentials,
  logout,
  removeWebauthnCredential,
  startWebauthnRegistration,
  startTotpEnrollment,
  verifyTotpEnrollment,
  verifyWebauthnRegistration,
  type AuthenticatedUser,
  type WebauthnCredentialSummary,
} from '../lib/auth'
import { getDocuments, type DocumentSignatureRequirement, type DocumentSummary } from '../lib/documents'
import { getPendingRegistryApprovals, type RegistryEntry } from '../lib/registry'

type SessionPageProps = {
  onLoggedOut?: () => void
  onNavigate: (to: string) => void
  onSessionExpired?: () => void
  onUserUpdated?: (user: AuthenticatedUser) => void
  user: AuthenticatedUser
}

type LogoutState = 'idle' | 'submitting'
type WebauthnState = 'idle' | 'loading' | 'registering'
type TotpEnrollmentState = 'idle' | 'loading' | 'verifying'

type PendingSignatureQueueItem = {
  document: DocumentSummary
  kind: 'role' | 'user'
  requirement?: DocumentSignatureRequirement | null
}

type WebauthnRegistrationResult =
  | {
      kind: 'success'
      message: string
    }
  | {
      kind: 'error'
      message: string
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

const bufferToBase64Url = (buffer: ArrayBuffer): string => {
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

const supportsPasskeyEnrollment = (role: AuthenticatedUser['role']) =>
  role === 'admin' || role === 'coordinator'

const canApproveMigrantRegistrations = (role: AuthenticatedUser['role']) =>
  role === 'admin' || role === 'coordinator'

const formatDashboardDate = (value?: string | null) => {
  if (!value) {
    return 'Date unavailable'
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const getPendingRequirementLabel = (requirement?: DocumentSignatureRequirement | null) => {
  if (!requirement) {
    return 'Optional signature'
  }

  if (requirement.signerUser?.email) {
    return requirement.signerUser.email
  }

  if (requirement.signerRole) {
    return requirement.signerRole
      .split('_')
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join(' ')
  }

  return 'Assigned signer'
}

const requirementMatchesUser = (
  requirement: DocumentSignatureRequirement,
  user: AuthenticatedUser,
) => {
  if (requirement.signerUser?.id != null) {
    return requirement.signerUser.id === user.id
  }

  return requirement.signerRole === user.role
}

const getQueueKindForRequirement = (
  requirement: DocumentSignatureRequirement,
): PendingSignatureQueueItem['kind'] =>
  requirement.signerUser?.id != null ? 'user' : 'role'

const getPendingSignatureQueue = (
  documents: DocumentSummary[],
  user: AuthenticatedUser,
): PendingSignatureQueueItem[] =>
  documents
    .filter((document) => {
      const alreadySigned = document.currentRevision?.signatures?.some(
        (signature) => signature.signedBy.id === user.id,
      ) ?? false

      return document.capabilities?.canSignCurrent === true && !alreadySigned
    })
    .map((document): PendingSignatureQueueItem => {
      const pendingRequirements = document.approval?.signatureRequirements
        ?.filter((requirement) => !requirement.fulfilledAt && !requirement.fulfilledBySignatureId)
        .sort((left, right) => left.sequence - right.sequence) ?? []
      const matchingRequirement = pendingRequirements.find((requirement) =>
        requirementMatchesUser(requirement, user),
      )

      return {
        document,
        kind: matchingRequirement ? getQueueKindForRequirement(matchingRequirement) : 'role',
        requirement: matchingRequirement ?? null,
      }
    })

const getMigrantApprovalName = (entry: RegistryEntry) =>
  String(entry.payload_json.fullName || entry.payload_json.full_name || `Registration #${entry.id}`)

export function SessionPage({
  onLoggedOut,
  onNavigate,
  onSessionExpired,
  onUserUpdated,
  user,
}: SessionPageProps) {
  const [logoutState, setLogoutState] = useState<LogoutState>('idle')
  const [logoutError, setLogoutError] = useState<string | null>(null)
  const [webauthnState, setWebauthnState] = useState<WebauthnState>('loading')
  const [webauthnError, setWebauthnError] = useState<string | null>(null)
  const [webauthnResult, setWebauthnResult] =
    useState<WebauthnRegistrationResult | null>(null)
  const [removingCredentialId, setRemovingCredentialId] = useState<string | null>(null)
  const [registeredCredentials, setRegisteredCredentials] = useState<
    WebauthnCredentialSummary[]
  >([])
  const [totpEnrollmentState, setTotpEnrollmentState] =
    useState<TotpEnrollmentState>('idle')
  const [totpEnrollmentCode, setTotpEnrollmentCode] = useState('')
  const [totpEnrollmentSecret, setTotpEnrollmentSecret] = useState<string | null>(null)
  const [totpEnrollmentUri, setTotpEnrollmentUri] = useState<string | null>(null)
  const [totpEnrollmentQrCode, setTotpEnrollmentQrCode] = useState<string | null>(null)
  const [totpEnrollmentQrError, setTotpEnrollmentQrError] = useState<string | null>(null)
  const [totpEnrollmentError, setTotpEnrollmentError] = useState<string | null>(null)
  const [totpEnrollmentSuccess, setTotpEnrollmentSuccess] = useState<string | null>(null)
  const [pendingSignatureQueue, setPendingSignatureQueue] = useState<PendingSignatureQueueItem[]>([])
  const [pendingMigrantApprovals, setPendingMigrantApprovals] = useState<RegistryEntry[]>([])
  const [signatureQueueError, setSignatureQueueError] = useState<string | null>(null)
  const [migrantApprovalQueueError, setMigrantApprovalQueueError] = useState<string | null>(null)
  const [isSignatureQueueLoading, setIsSignatureQueueLoading] = useState(false)
  const [isMigrantApprovalQueueLoading, setIsMigrantApprovalQueueLoading] = useState(false)
  const [notificationPermission, setNotificationPermission] = useState<NotificationPermission>(() =>
    typeof Notification === 'undefined' ? 'denied' : Notification.permission,
  )
  const hasLoadedSignatureQueueRef = useRef(false)
  const knownPendingSignatureIdsRef = useRef<Set<string>>(new Set())
  const security = user.capabilities.security
  const isSecurityEnrollmentPending = security.enforced && !security.isFullyEnrolled
  const assignedSignatureQueue = pendingSignatureQueue.filter((item) => item.kind === 'user')
  const roleSignatureQueue = pendingSignatureQueue.filter((item) => item.kind === 'role')
  const actionableMigrantApprovals = pendingMigrantApprovals.filter(
    (entry) => user.role === 'admin' || (entry.pending_requested_by ?? entry.created_by) !== user.id,
  )

  const loadPendingSignatureQueue = useCallback(async () => {
    if (!user.capabilities.modules.documents) {
      setPendingSignatureQueue([])
      return
    }

    setIsSignatureQueueLoading(true)
    setSignatureQueueError(null)

    try {
      const response = await getDocuments()
      const nextQueue = getPendingSignatureQueue(response.documents, user)
      const nextIds = new Set(
        nextQueue.map((item) => `${item.document.id}:${item.document.currentRevision?.id ?? 'current'}`),
      )

      if (
        hasLoadedSignatureQueueRef.current &&
        notificationPermission === 'granted'
      ) {
        const newItems = nextQueue.filter((item) =>
          !knownPendingSignatureIdsRef.current.has(
            `${item.document.id}:${item.document.currentRevision?.id ?? 'current'}`,
          ),
        )

        newItems.slice(0, 3).forEach((item) => {
          new Notification('Document ready to sign', {
            body: `${item.document.title} is available for your signature.`,
            tag: `document-signature-${item.document.id}`,
          })
        })
      }

      hasLoadedSignatureQueueRef.current = true
      knownPendingSignatureIdsRef.current = nextIds
      setPendingSignatureQueue(nextQueue)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSignatureQueueError(
        error instanceof Error ? error.message : 'Unable to load pending signatures.',
      )
    } finally {
      setIsSignatureQueueLoading(false)
    }
  }, [notificationPermission, onSessionExpired, user])

  const loadPendingMigrantApprovals = useCallback(async () => {
    if (!canApproveMigrantRegistrations(user.role)) {
      setPendingMigrantApprovals([])
      return
    }

    setIsMigrantApprovalQueueLoading(true)
    setMigrantApprovalQueueError(null)

    try {
      const response = await getPendingRegistryApprovals()
      setPendingMigrantApprovals(response.data)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setMigrantApprovalQueueError(
        error instanceof Error ? error.message : 'Unable to load pending migrant approvals.',
      )
    } finally {
      setIsMigrantApprovalQueueLoading(false)
    }
  }, [onSessionExpired, user.role])

  useEffect(() => {
    if (!supportsPasskeyEnrollment(user.role)) {
      setWebauthnState('idle')
      setRegisteredCredentials([])
      setWebauthnError(null)
      setWebauthnResult(null)
      return
    }

    let isMounted = true

    getWebauthnCredentials()
      .then((response) => {
        if (!isMounted) {
          return
        }

        setRegisteredCredentials(response.credentials)
        setWebauthnState('idle')
      })
      .catch((error) => {
        if (!isMounted) {
          return
        }

        setWebauthnError(
          error instanceof Error ? error.message : 'Failed to load security keys.',
        )
        setWebauthnState('idle')
      })

    return () => {
      isMounted = false
    }
  }, [user.role])

  useEffect(() => {
    void loadPendingSignatureQueue()
    void loadPendingMigrantApprovals()

    const intervalId = window.setInterval(() => {
      void loadPendingSignatureQueue()
      void loadPendingMigrantApprovals()
    }, 60_000)

    return () => {
      window.clearInterval(intervalId)
    }
  }, [loadPendingMigrantApprovals, loadPendingSignatureQueue])

  const handleEnableNotifications = async () => {
    if (typeof Notification === 'undefined') {
      setNotificationPermission('denied')
      return
    }

    const permission = await Notification.requestPermission()
    setNotificationPermission(permission)
  }

  useEffect(() => {
    let isMounted = true

    if (!totpEnrollmentUri) {
      setTotpEnrollmentQrCode(null)
      setTotpEnrollmentQrError(null)
      return
    }

    toDataURL(totpEnrollmentUri, {
      errorCorrectionLevel: 'M',
      margin: 1,
      width: 240,
      color: {
        dark: '#0f2640ff',
        light: '#fffdf8ff',
      },
    })
      .then((dataUrl) => {
        if (!isMounted) {
          return
        }

        setTotpEnrollmentQrCode(dataUrl)
        setTotpEnrollmentQrError(null)
      })
      .catch(() => {
        if (!isMounted) {
          return
        }

        setTotpEnrollmentQrCode(null)
        setTotpEnrollmentQrError(
          'QR generation failed in this browser. Use the secret or OTPAuth URI manually.',
        )
      })

    return () => {
      isMounted = false
    }
  }, [totpEnrollmentUri])

  const refreshCurrentUser = async () => {
    try {
      const response = await getCurrentUser()
      onUserUpdated?.(response.user)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
      }
    }
  }

  const handleLogout = async () => {
    setLogoutError(null)
    setLogoutState('submitting')

    try {
      await logout()
      onLoggedOut?.()
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setLogoutError(
        error instanceof ApiRequestError || error instanceof Error
          ? error.message
          : 'The session could not be closed.',
      )
      setLogoutState('idle')
    }
  }

  const handleRegisterSecurityKey = async () => {
    if (!window.isSecureContext || !('PublicKeyCredential' in window)) {
      setWebauthnResult({
        kind: 'error',
        message: 'WebAuthn is only available in a secure context and supported browser.',
      })
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setWebauthnResult({
        kind: 'error',
        message:
          'WebAuthn registration requires localhost or a domain name. Open this app from localhost or your staging domain.',
      })
      return
    }

    setWebauthnResult(null)
    setWebauthnError(null)
    setWebauthnState('registering')

    try {
      const optionsResponse = await startWebauthnRegistration()
      const options = optionsResponse.options

      const credential = await navigator.credentials.create({
        publicKey: {
          ...options,
          challenge: base64UrlToArrayBuffer(options.challenge),
          user: {
            ...options.user,
            id: base64UrlToArrayBuffer(options.user.id),
          },
          excludeCredentials: options.excludeCredentials?.map((credentialOption) => ({
            ...credentialOption,
            id: base64UrlToArrayBuffer(credentialOption.id),
          })),
        },
      })

      if (!(credential instanceof PublicKeyCredential)) {
        throw new Error('The security key did not return a valid WebAuthn credential.')
      }

      const response = credential.response

      if (!(response instanceof AuthenticatorAttestationResponse)) {
        throw new Error('WebAuthn attestation response is not valid for registration.')
      }

      const attestationResponse = response as AuthenticatorAttestationResponse & {
        getAuthenticatorData?: () => ArrayBuffer
        getPublicKey?: () => ArrayBuffer | null
        getPublicKeyAlgorithm?: () => number
      }

      const authenticatorData = attestationResponse.getAuthenticatorData?.()
      const publicKey = attestationResponse.getPublicKey?.()
      const publicKeyAlgorithm = attestationResponse.getPublicKeyAlgorithm?.()

      if (!authenticatorData || !publicKey || typeof publicKeyAlgorithm !== 'number') {
        throw new Error(
          'Your browser does not expose WebAuthn credential verification metadata. Update the browser and try again.',
        )
      }

      const registrationResponse = await verifyWebauthnRegistration({
        id: credential.id,
        rawId: bufferToBase64Url(credential.rawId),
        type: 'public-key',
        response: {
          attestationObject: bufferToBase64Url(response.attestationObject),
          clientDataJSON: bufferToBase64Url(response.clientDataJSON),
          authenticatorData: bufferToBase64Url(authenticatorData),
          publicKey: bufferToBase64Url(publicKey),
          publicKeyAlgorithm,
        },
        transports: response.getTransports ? response.getTransports() : undefined,
      })

      setRegisteredCredentials((current) => [
        registrationResponse.credential,
        ...current,
      ])
      setWebauthnResult({
        kind: 'success',
        message: registrationResponse.message,
      })
      await refreshCurrentUser()
    } catch (error) {
      const message =
        error instanceof Error
          ? error.name === 'NotAllowedError'
            ? 'Security key registration was cancelled.'
            : error.message
          : 'Security key registration failed.'

      setWebauthnResult({
        kind: 'error',
        message,
      })
    } finally {
      setWebauthnState('idle')
    }
  }

  const handleRemoveSecurityKey = async (credentialId: string) => {
    setWebauthnResult(null)
    setWebauthnError(null)
    setRemovingCredentialId(credentialId)

    try {
      const response = await removeWebauthnCredential(credentialId)

      setRegisteredCredentials((current) =>
        current.filter((credential) => credential.id !== credentialId),
      )
      setWebauthnResult({
        kind: 'success',
        message: response.message,
      })
      await refreshCurrentUser()
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setWebauthnResult({
        kind: 'error',
        message:
          error instanceof Error
            ? error.message
            : 'Security key removal failed.',
      })
    } finally {
      setRemovingCredentialId(null)
    }
  }

  const handleTotpEnrollmentOptions = async () => {
    setTotpEnrollmentError(null)
    setTotpEnrollmentSuccess(null)
    setTotpEnrollmentState('loading')

    try {
      const response = await startTotpEnrollment()
      setTotpEnrollmentSecret(response.enrollment.secret)
      setTotpEnrollmentUri(response.enrollment.otpauthUri)
      setTotpEnrollmentQrError(null)
      setTotpEnrollmentSuccess('TOTP setup secret created. Add it to your authenticator app, then verify the 6-digit code.')
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setTotpEnrollmentError(
        error instanceof Error
          ? error.message
          : 'Failed to create TOTP setup challenge.',
      )
    } finally {
      setTotpEnrollmentState('idle')
    }
  }

  const handleTotpEnrollmentVerify = async () => {
    if (!/^\d{6}$/.test(totpEnrollmentCode.trim())) {
      setTotpEnrollmentError('Use a valid 6-digit authentication code.')
      setTotpEnrollmentSuccess(null)
      return
    }

    setTotpEnrollmentError(null)
    setTotpEnrollmentSuccess(null)
    setTotpEnrollmentState('verifying')

    try {
      const response = await verifyTotpEnrollment({
        code: totpEnrollmentCode.trim(),
      })

      setTotpEnrollmentSuccess(response.message)
      setTotpEnrollmentSecret(null)
      setTotpEnrollmentUri(null)
      setTotpEnrollmentQrCode(null)
      setTotpEnrollmentQrError(null)
      setTotpEnrollmentCode('')
      onUserUpdated?.(response.user)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setTotpEnrollmentError(
        error instanceof Error
          ? error.message
          : 'TOTP verification failed.',
      )
    } finally {
      setTotpEnrollmentState('idle')
    }
  }

  return (
    <section className="workspace-stack">
      <section className="workspace-panel" aria-label="Current session">
        <h2 className="workspace-panel__title">Current session</h2>
        <ul className="route-checklist">
          <li>Name: {user.name}</li>
          <li>Email: {user.email}</li>
          <li>
            Role: <RoleBadge role={user.role} />
          </li>
          <li>User ID: {user.id}</li>
        </ul>

        <div className="session-actions">
          <button
            className="session-action"
            disabled={logoutState === 'submitting'}
            onClick={handleLogout}
            type="button"
          >
            <AppIcon name="logout" />
            {logoutState === 'submitting' ? 'Signing out...' : 'Sign out'}
          </button>
        </div>

        {logoutError ? (
          <div className="login-feedback login-feedback--error">
            {logoutError}
          </div>
        ) : null}
      </section>

      {isSecurityEnrollmentPending ? (
        <section className="workspace-panel workspace-panel--accent" aria-label="Security enrollment">
          <h2 className="workspace-panel__title">Security enrollment required</h2>
          <p className="workspace-panel__copy">
            Complete the required factors before accessing protected modules for this role.
          </p>
          <ul className="route-checklist">
            {security.requires.totp ? (
              <li>
                TOTP: {security.enrolled.totp ? 'enrolled' : 'missing'}
              </li>
            ) : null}
            {security.requires.passkey ? (
              <li>
                Passkey: {security.enrolled.passkey ? 'enrolled' : 'missing'}
              </li>
            ) : null}
          </ul>
        </section>
      ) : null}

      {user.capabilities.modules.documents ? (
        <section className="workspace-panel dashboard-signature-queue" aria-label="Pending signatures">
          <div className="dashboard-signature-queue__header">
            <div>
              <h2 className="workspace-panel__title">Documents pending signature</h2>
              <p className="workspace-panel__copy">
                Queue of approved documents that are currently available for this session to sign.
              </p>
            </div>
            <div className="session-actions">
              {typeof Notification !== 'undefined' && notificationPermission !== 'granted' ? (
                <button
                  className="session-action session-action--quiet"
                  onClick={handleEnableNotifications}
                  type="button"
                >
                  <AppIcon name="verify" />
                  Enable notifications
                </button>
              ) : null}
              <button
                className="session-action session-action--quiet"
                disabled={isSignatureQueueLoading}
                onClick={() => void loadPendingSignatureQueue()}
                type="button"
              >
                <AppIcon name="refresh" />
                {isSignatureQueueLoading ? 'Refreshing...' : 'Refresh queue'}
              </button>
            </div>
          </div>

          {signatureQueueError ? (
            <div className="login-feedback login-feedback--error">
              {signatureQueueError}
            </div>
          ) : null}

          {!isSignatureQueueLoading && pendingSignatureQueue.length === 0 && !signatureQueueError ? (
            <p className="workspace-panel__copy">
              There are no documents available for your signature right now.
            </p>
          ) : null}

          {assignedSignatureQueue.length > 0 ? (
            <section className="signature-queue-section" aria-label="Assigned signatures">
              <h3>For you to sign</h3>
              <div className="signature-queue-list">
                {assignedSignatureQueue.map((item) => (
                  <article className="signature-queue-card" key={`assigned-${item.document.id}`}>
                    <div>
                      <strong>{item.document.title}</strong>
                      <span>
                        {getPendingRequirementLabel(item.requirement)} · Revision{' '}
                        {item.document.currentRevision?.revisionNumber ?? 'current'}
                      </span>
                      <small>Ready since {formatDashboardDate(item.document.approval?.approvedAt)}</small>
                    </div>
                    <button
                      className="session-action"
                      onClick={() =>
                        onNavigate(`${APP_DOCUMENTS_PATH}?documentId=${item.document.id}`)
                      }
                      type="button"
                    >
                      <AppIcon name="sign" />
                      Open to sign
                    </button>
                  </article>
                ))}
              </div>
            </section>
          ) : null}

          {roleSignatureQueue.length > 0 ? (
            <section className="signature-queue-section" aria-label="Role signatures">
              <h3>For your role</h3>
              <div className="signature-queue-list">
                {roleSignatureQueue.map((item) => (
                  <article className="signature-queue-card" key={`role-${item.document.id}`}>
                    <div>
                      <strong>{item.document.title}</strong>
                      <span>
                        {getPendingRequirementLabel(item.requirement)} · Revision{' '}
                        {item.document.currentRevision?.revisionNumber ?? 'current'}
                      </span>
                      <small>Ready since {formatDashboardDate(item.document.approval?.approvedAt)}</small>
                    </div>
                    <button
                      className="session-action"
                      onClick={() =>
                        onNavigate(`${APP_DOCUMENTS_PATH}?documentId=${item.document.id}`)
                      }
                      type="button"
                    >
                      <AppIcon name="sign" />
                      Open to sign
                    </button>
                  </article>
                ))}
              </div>
            </section>
          ) : null}
        </section>
      ) : null}

      {canApproveMigrantRegistrations(user.role) ? (
        <section className="workspace-panel dashboard-signature-queue" aria-label="Pending migrant approvals">
          <div className="dashboard-signature-queue__header">
            <div>
              <h2 className="workspace-panel__title">Migrant registrations pending approval</h2>
              <p className="workspace-panel__copy">
                Queue of submitted migrant registrations available for coordinator/admin review.
              </p>
            </div>
            <div className="session-actions">
              <button
                className="session-action session-action--quiet"
                disabled={isMigrantApprovalQueueLoading}
                onClick={() => void loadPendingMigrantApprovals()}
                type="button"
              >
                <AppIcon name="refresh" />
                {isMigrantApprovalQueueLoading ? 'Refreshing...' : 'Refresh queue'}
              </button>
            </div>
          </div>

          {migrantApprovalQueueError ? (
            <div className="login-feedback login-feedback--error">
              {migrantApprovalQueueError}
            </div>
          ) : null}

          {!isMigrantApprovalQueueLoading &&
          actionableMigrantApprovals.length === 0 &&
          !migrantApprovalQueueError ? (
            <p className="workspace-panel__copy">
              There are no migrant registrations available for your approval right now.
            </p>
          ) : null}

          {actionableMigrantApprovals.length > 0 ? (
            <div className="signature-queue-list">
              {actionableMigrantApprovals.map((entry) => (
                <article className="signature-queue-card" key={`migrant-${entry.id}`}>
                  <div>
                    <strong>{getMigrantApprovalName(entry)}</strong>
                    <span>
                      {entry.payload_json.countryOfOrigin
                        ? String(entry.payload_json.countryOfOrigin)
                        : 'Country unavailable'}
                    </span>
                    <small>Submitted {formatDashboardDate(entry.created_at)}</small>
                  </div>
                  <button
                    className="session-action"
                    onClick={() => onNavigate(APP_MIGRANT_APPROVALS_PATH)}
                    type="button"
                  >
                    <AppIcon name="verify" />
                    Open approvals
                  </button>
                </article>
              ))}
            </div>
          ) : null}
        </section>
      ) : null}

      {isSecurityEnrollmentPending && security.requires.totp ? (
        <section className="workspace-panel" aria-label="TOTP enrollment">
          <h2 className="workspace-panel__title">TOTP enrollment</h2>
          <ul className="route-checklist">
            <li>Generate a setup secret for your authenticator app.</li>
            <li>Verify one 6-digit code to activate TOTP on this account.</li>
          </ul>

          <div className="session-actions">
            <button
              className="session-action"
              disabled={totpEnrollmentState !== 'idle'}
              onClick={handleTotpEnrollmentOptions}
              type="button"
            >
              <AppIcon name="verify" />
              {totpEnrollmentState === 'loading'
                ? 'Generating setup...'
                : 'Generate TOTP setup'}
            </button>
          </div>

          {totpEnrollmentSecret ? (
            <div className="workspace-panel__copy totp-enrollment__details">
              {totpEnrollmentQrCode ? (
                <figure className="totp-enrollment__qr">
                  <img
                    alt="TOTP enrollment QR code"
                    className="totp-enrollment__qr-image"
                    height={240}
                    src={totpEnrollmentQrCode}
                    width={240}
                  />
                  <figcaption>Scan this QR code with your authenticator app.</figcaption>
                </figure>
              ) : null}

              {totpEnrollmentQrError ? (
                <p className="totp-enrollment__qr-error">{totpEnrollmentQrError}</p>
              ) : null}

              <p><strong>Secret:</strong> <code>{totpEnrollmentSecret}</code></p>
              {totpEnrollmentUri ? (
                <p><strong>OTPAuth URI:</strong> <code>{totpEnrollmentUri}</code></p>
              ) : null}
              <label className="login-field">
                <span className="login-field__label">Authenticator code</span>
                <input
                  className="login-field__input"
                  inputMode="numeric"
                  maxLength={6}
                  onChange={(event) => setTotpEnrollmentCode(event.target.value)}
                  placeholder="000000"
                  type="text"
                  value={totpEnrollmentCode}
                />
              </label>
              <div className="session-actions">
                <button
                  className="session-action"
                  disabled={totpEnrollmentState !== 'idle'}
                  onClick={handleTotpEnrollmentVerify}
                  type="button"
                >
                  <AppIcon name="sign" />
                  {totpEnrollmentState === 'verifying'
                    ? 'Verifying code...'
                    : 'Verify and enable TOTP'}
                </button>
              </div>
            </div>
          ) : null}

          {totpEnrollmentError ? (
            <div className="login-feedback login-feedback--error">{totpEnrollmentError}</div>
          ) : null}
          {totpEnrollmentSuccess ? (
            <div className="login-feedback login-feedback--success">{totpEnrollmentSuccess}</div>
          ) : null}
        </section>
      ) : null}

      {supportsPasskeyEnrollment(user.role) ? (
        <section className="workspace-panel" aria-label="Security key registration">
          <h2 className="workspace-panel__title">Security keys (dev preview)</h2>
          <ul className="route-checklist">
            <li>Register your FIDO2/WebAuthn key to the current account.</li>
            <li>Once registered, this key can be used for passwordless sign-in.</li>
          </ul>

          <div className="session-actions">
            <button
              className="session-action"
              disabled={webauthnState !== 'idle'}
              onClick={handleRegisterSecurityKey}
              type="button"
            >
              <AppIcon name="key" />
              {webauthnState === 'registering'
                ? 'Registering key...'
                : 'Register security key'}
            </button>
          </div>

          {webauthnError ? (
            <div className="login-feedback login-feedback--error">{webauthnError}</div>
          ) : null}

          {webauthnResult ? (
            <div
              className={
                webauthnResult.kind === 'success'
                  ? 'login-feedback login-feedback--success'
                  : 'login-feedback login-feedback--error'
              }
            >
              {webauthnResult.message}
            </div>
          ) : null}

          {registeredCredentials.length > 0 ? (
            <ul className="route-checklist">
              {registeredCredentials.map((credential) => (
                <li key={credential.id}>
                  <span>
                    {credential.name ?? 'Security key'} ({credential.id.slice(0, 12)}...)
                  </span>
                  <button
                    className="session-action session-action--danger session-action--inline"
                    disabled={
                      webauthnState !== 'idle' || removingCredentialId === credential.id
                    }
                    onClick={() => handleRemoveSecurityKey(credential.id)}
                    type="button"
                  >
                    <AppIcon name="delete" />
                    {removingCredentialId === credential.id ? 'Removing...' : 'Remove'}
                  </button>
                </li>
              ))}
            </ul>
          ) : (
            <p className="workspace-panel__copy">
              No security keys are registered for this account yet.
            </p>
          )}
        </section>
      ) : null}
    </section>
  )
}
