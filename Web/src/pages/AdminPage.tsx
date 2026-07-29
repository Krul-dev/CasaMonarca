import type { AuthenticatedUser } from '../lib/auth'
import { AppIcon } from '../components/ui/AppIcon'
import { RoleBadge } from '../components/ui/RoleBadge'
import { type CSSProperties, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { ApiRequestError } from '../lib/api'
import { APP_DOCUMENTS_PATH } from '../config/appRoutes'
import {
  getAdminUsers,
  getMigrantSigningLedger,
  getSigningLedger,
  getVerificationPackageSigningKey,
  startAdminUserRecovery,
  startAdminUserRoleUpdate,
  startAdminUserStatusUpdate,
  startVerificationPackageSigningKeyRotation,
  verifyAdminUserRecovery,
  verifyAdminUserRoleUpdate,
  verifyAdminUserStatusUpdate,
  verifyVerificationPackageSigningKeyRotation,
  type AssignableUserRole,
  type AdminUserRecoveryAction,
  type AdminUserListResponse,
  type AdminUserStatusAction,
  type AdminUserSummary,
  type MigrantSigningLedgerRegistration,
  type MigrantSigningLedgerSigner,
  type SigningLedgerDocument,
  type SigningLedgerRevision,
  type SigningLedgerSigner,
  type SigningLedgerSignature,
  type VerificationPackageSigningKeySummary,
} from '../lib/adminUsers'
import { getRoleLabel } from '../config/appRoutes'
import { cancelSecurityChallenge } from '../lib/securityChallenges'
import { getWebauthnAssertion } from '../lib/webauthn'

type AdminPageProps = {
  locationSearch?: string
  onNavigate?: (to: string) => void
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

const formatOptionalDate = (value?: string | null) => {
  if (!value) {
    return 'Not recorded yet'
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const formatFingerprint = (value?: string | null) => value || 'Not configured'

const formatLedgerLabel = (value: string) =>
  value
    .split('_')
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')

const getDefaultSigningKeyId = () => `cm-package-${new Date().toISOString().slice(0, 10)}`

const getSigningKeyProvisionCommand = (keyId?: string | null) =>
  `php artisan verification-packages:generate-signing-key --key-id=${keyId || getDefaultSigningKeyId()} --write-env`

const getEnrollmentLabel = (account: AdminUserSummary) => {
  if (account.enrollment.isFullyEnrolled) {
    return 'Complete'
  }

  const missing = [
    account.enrollment.missing.totp ? 'TOTP' : null,
    account.enrollment.missing.passkey ? 'Passkey' : null,
  ].filter(Boolean)

  return missing.length > 0 ? `Missing ${missing.join(' + ')}` : 'Not required'
}

const ASSIGNABLE_ROLES: AssignableUserRole[] = [
  'coordinator',
  'non_coordinator',
  'volunteer',
]

type AdminTabId = 'accounts' | 'security' | 'signing' | 'migrant-signing' | 'system' | 'audit'

const ADMIN_TABS: Array<{
  copy: string
  id: AdminTabId
  label: string
}> = [
  {
    id: 'accounts',
    label: 'Account Management',
    copy: 'Users, roles, enrollment recovery, and account status.',
  },
  {
    id: 'security',
    label: 'Security Keys',
    copy: 'Package signing trust anchor and key rotation.',
  },
  {
    id: 'signing',
    label: 'Signing Trust',
    copy: 'Users, signatures, and signed document revisions.',
  },
  {
    id: 'migrant-signing',
    label: 'Migrant Signing Trust',
    copy: 'Registration submissions, reviews, approvals, and signing keys.',
  },
  {
    id: 'system',
    label: 'System Configuration',
    copy: 'Privileged security, invite, and operational policies.',
  },
  {
    id: 'audit',
    label: 'Audit Overview',
    copy: 'Admin-facing summaries for privileged activity.',
  },
]

const ADMIN_ACTIVE_TAB_SESSION_KEY_PREFIX = 'casa-monarca.admin.activeTab'
const ADMIN_ACCOUNT_FOCUS_SESSION_KEY_PREFIX = 'casa-monarca.admin.accountFocus'

const isAdminTabId = (value: string | null): value is AdminTabId =>
  ADMIN_TABS.some((tab) => tab.id === value)

const getAdminActiveTabSessionKey = (userId: number) =>
  `${ADMIN_ACTIVE_TAB_SESSION_KEY_PREFIX}:${userId}`

const getAdminAccountFocusSessionKey = (viewerUserId: number, focusToken: string) =>
  `${ADMIN_ACCOUNT_FOCUS_SESSION_KEY_PREFIX}:${viewerUserId}:${focusToken}`

const readStoredAdminTab = (userId: number): AdminTabId => {
  if (typeof window === 'undefined') {
    return 'accounts'
  }

  try {
    const storedTab = window.sessionStorage.getItem(getAdminActiveTabSessionKey(userId))

    return isAdminTabId(storedTab) ? storedTab : 'accounts'
  } catch {
    return 'accounts'
  }
}

const storeAdminTab = (userId: number, tab: AdminTabId) => {
  try {
    window.sessionStorage.setItem(getAdminActiveTabSessionKey(userId), tab)
  } catch {
    // Session storage is a convenience only; tab switching must still work if it is unavailable.
  }
}

const parsePositiveIntegerParam = (params: URLSearchParams, key: string) => {
  const value = params.get(key)

  if (!value || !/^\d+$/.test(value)) {
    return null
  }

  const parsedValue = Number(value)

  return Number.isSafeInteger(parsedValue) && parsedValue > 0 ? parsedValue : null
}

const parseAccountFocusTokenParam = (params: URLSearchParams) => {
  const value = params.get('focus')

  return value && /^[a-zA-Z0-9_-]{1,96}$/.test(value) ? value : null
}

const consumeAccountFocusToken = (viewerUserId: number, focusToken: string | null) => {
  if (!focusToken || typeof window === 'undefined') {
    return false
  }

  try {
    const storageKey = getAdminAccountFocusSessionKey(viewerUserId, focusToken)

    if (window.sessionStorage.getItem(storageKey)) {
      return false
    }

    window.sessionStorage.setItem(storageKey, 'consumed')
    return true
  } catch {
    return true
  }
}

const getRoleSelectValue = (
  account: AdminUserSummary,
  roleDraftByUserId: Record<number, AssignableUserRole>,
) => {
  if (account.role === 'admin') {
    return 'admin'
  }

  return roleDraftByUserId[account.id] ?? 'non_coordinator'
}

const isCoordinatorPromotionBlocked = (
  account: AdminUserSummary,
  roleDraftByUserId: Record<number, AssignableUserRole>,
) =>
  account.role !== 'coordinator' &&
  getRoleSelectValue(account, roleDraftByUserId) === 'coordinator' &&
  account.enrollment.passkeyCount === 0

type SigningLedgerGraphEdge = {
  document: SigningLedgerDocument
  id: string
  revision: SigningLedgerRevision
  signature: SigningLedgerSignature | null
  signer: {
    email: string
    id: number
    name: string
    role: SigningLedgerSigner['role']
    signatureCount?: number
  } | null
}

type SigningLedgerGraphRevision = {
  document: SigningLedgerDocument
  edges: SigningLedgerGraphEdge[]
  id: string
  revision: SigningLedgerRevision
}

type SigningLedgerGraphDocument = {
  document: SigningLedgerDocument
  id: string
  revisions: SigningLedgerGraphRevision[]
}

type SigningLedgerGraphSigner = {
  edges: SigningLedgerGraphEdge[]
  signer: NonNullable<SigningLedgerGraphEdge['signer']>
}

type SigningLedgerConnectorPath = {
  d: string
  id: string
  variant: 'key-revision' | 'user-key'
}

type SigningLedgerConnectorBounds = {
  height: number
  width: number
}

type SigningLedgerNodeOffsets = {
  key: Record<string, number>
  user: Record<string, number>
}

const areSigningLedgerNodeOffsetsEqual = (
  current: SigningLedgerNodeOffsets,
  next: SigningLedgerNodeOffsets,
) => {
  const currentKeyEntries = Object.entries(current.key)
  const nextKeyEntries = Object.entries(next.key)
  const currentUserEntries = Object.entries(current.user)
  const nextUserEntries = Object.entries(next.user)

  if (
    currentKeyEntries.length !== nextKeyEntries.length ||
    currentUserEntries.length !== nextUserEntries.length
  ) {
    return false
  }

  return nextKeyEntries.every(([key, value]) => current.key[key] === value) &&
    nextUserEntries.every(([key, value]) => current.user[key] === value)
}

const formatSecurityKeyIdentifier = (value?: string | null) => {
  if (!value) {
    return 'not recorded'
  }

  return value.length > 20 ? `${value.slice(0, 12)}...${value.slice(-6)}` : value
}

const getSigningKeyIdentifier = (edges: SigningLedgerGraphEdge[]) => {
  const credentialIds = Array.from(new Set(edges
    .map((edge) => edge.signature?.credential?.id)
    .filter((value): value is string => typeof value === 'string' && value.trim() !== '')))

  if (credentialIds.length > 0) {
    return {
      label: credentialIds.length > 1
        ? `${formatSecurityKeyIdentifier(credentialIds[0])} +${credentialIds.length - 1}`
        : formatSecurityKeyIdentifier(credentialIds[0]),
      title: credentialIds.join(', '),
    }
  }

  const fingerprints = Array.from(new Set(edges
    .map((edge) => edge.signature?.credential?.publicKeyFingerprintSha256)
    .filter((value): value is string => typeof value === 'string' && value.trim() !== '')))

  return {
    label: fingerprints.length > 0 ? formatSecurityKeyIdentifier(fingerprints[0]) : 'not recorded',
    title: fingerprints.join(', ') || 'No credential id recorded for these signatures',
  }
}

const buildSigningLedgerGraph = (
  documents: SigningLedgerDocument[],
  signers: SigningLedgerSigner[],
) => {
  const documentGroups: SigningLedgerGraphDocument[] = []
  const signerGroups: SigningLedgerGraphSigner[] = []
  const signersById = new Map(signers.map((signer) => [signer.id, signer]))
  const signerGroupsById = new Map<number, SigningLedgerGraphSigner>()

  documents.forEach((document) => {
    const documentGroup: SigningLedgerGraphDocument = {
      id: String(document.id),
      document,
      revisions: [],
    }

    document.revisions.forEach((revision) => {
      const revisionGroup: SigningLedgerGraphRevision = {
        id: `${document.id}:${revision.id}`,
        document,
        revision,
        edges: [],
      }
      const signatures = revision.signatures.length > 0 ? revision.signatures : [null]

      signatures.forEach((signature) => {
        const signer = signature?.signedBy
          ? signersById.get(signature.signedBy.id) ?? signature.signedBy
          : null

        revisionGroup.edges.push({
          id: `${signer?.id ?? 'unsigned'}:${document.id}:${revision.id}:${signature?.id ?? 'missing'}`,
          signer,
          document,
          revision,
          signature,
        })

        if (signer && signature) {
          let signerGroup = signerGroupsById.get(signer.id)

          if (!signerGroup) {
            const nextSignerGroup = {
              signer: signersById.get(signer.id) ?? signer,
              edges: [],
            }
            signerGroupsById.set(signer.id, nextSignerGroup)
            signerGroups.push(nextSignerGroup)
            signerGroup = nextSignerGroup
          }

          signerGroup.edges.push({
            id: `${signer.id}:${document.id}:${revision.id}:${signature.id}`,
            signer,
            document,
            revision,
            signature,
          })
        }
      })

      documentGroup.revisions.push(revisionGroup)
    })

    documentGroups.push(documentGroup)
  })

  return {
    documentGroups,
    signerGroups,
    unsignedSigners: signers.filter((signer) => signer.signatureCount === 0),
  }
}

export function AdminPage({ locationSearch, onNavigate, onSessionExpired, user }: AdminPageProps) {
  const adminQuery = useMemo(() => new URLSearchParams(locationSearch ?? ''), [locationSearch])
  const requestedTabParam = adminQuery.get('tab')
  const requestedAdminTab = isAdminTabId(requestedTabParam) ? requestedTabParam : null
  const requestedAccountId = parsePositiveIntegerParam(adminQuery, 'userId')
  const requestedAccountFocusToken = parseAccountFocusTokenParam(adminQuery)
  const requestedAccountFocusKey = requestedAccountFocusToken
    ? `${requestedAccountId ?? 'none'}:${requestedAccountFocusToken}`
    : null
  const [activeTab, setActiveTab] = useState<AdminTabId>(() => readStoredAdminTab(user.id))
  const [highlightedAccountId, setHighlightedAccountId] = useState<number | null>(null)
  const [highlightToken, setHighlightToken] = useState(0)
  const [directory, setDirectory] = useState<AdminUserSummary[]>([])
  const [directoryError, setDirectoryError] = useState<string | null>(null)
  const [isDirectoryLoading, setIsDirectoryLoading] = useState(true)
  const [roleDraftByUserId, setRoleDraftByUserId] = useState<Record<number, AssignableUserRole>>({})
  const [roleUpdateError, setRoleUpdateError] = useState<string | null>(null)
  const [roleUpdateMessage, setRoleUpdateMessage] = useState<string | null>(null)
  const [passwordResetLink, setPasswordResetLink] = useState<string | null>(null)
  const [recoveryAction, setRecoveryAction] = useState<AdminUserRecoveryAction | null>(null)
  const [recoveringUserId, setRecoveringUserId] = useState<number | null>(null)
  const [statusAction, setStatusAction] = useState<AdminUserStatusAction | null>(null)
  const [signingKey, setSigningKey] = useState<VerificationPackageSigningKeySummary | null>(null)
  const [signingKeyError, setSigningKeyError] = useState<string | null>(null)
  const [signingKeyMessage, setSigningKeyMessage] = useState<string | null>(null)
  const [isSigningKeyLoading, setIsSigningKeyLoading] = useState(true)
  const [isRotatingSigningKey, setIsRotatingSigningKey] = useState(false)
  const [signingLedgerDocuments, setSigningLedgerDocuments] = useState<SigningLedgerDocument[]>([])
  const [signingLedger, setSigningLedger] = useState<SigningLedgerSigner[]>([])
  const [signingLedgerError, setSigningLedgerError] = useState<string | null>(null)
  const [isSigningLedgerLoading, setIsSigningLedgerLoading] = useState(true)
  const [migrantSigningRegistrations, setMigrantSigningRegistrations] = useState<MigrantSigningLedgerRegistration[]>([])
  const [migrantSigningSigners, setMigrantSigningSigners] = useState<MigrantSigningLedgerSigner[]>([])
  const [migrantSigningError, setMigrantSigningError] = useState<string | null>(null)
  const [isMigrantSigningLoading, setIsMigrantSigningLoading] = useState(true)
  const [updatingStatusUserId, setUpdatingStatusUserId] = useState<number | null>(null)
  const [updatingRoleUserId, setUpdatingRoleUserId] = useState<number | null>(null)
  const signingGraphBodyRef = useRef<HTMLDivElement | null>(null)
  const accountCardRefs = useRef<Map<number, HTMLElement>>(new Map())
  const signingUserNodeRefs = useRef<Map<string, HTMLElement>>(new Map())
  const signingKeyNodeRefs = useRef<Map<string, HTMLElement>>(new Map())
  const signingRevisionNodeRefs = useRef<Map<string, HTMLElement>>(new Map())
  const [signingLedgerConnectorPaths, setSigningLedgerConnectorPaths] = useState<SigningLedgerConnectorPath[]>([])
  const [signingLedgerConnectorBounds, setSigningLedgerConnectorBounds] = useState<SigningLedgerConnectorBounds>({
    height: 1,
    width: 1,
  })
  const [signingLedgerGraphBodyMinHeight, setSigningLedgerGraphBodyMinHeight] = useState(1)
  const [signingLedgerNodeOffsets, setSigningLedgerNodeOffsets] = useState<SigningLedgerNodeOffsets>({
    key: {},
    user: {},
  })
  const signingLedgerNodeOffsetsRef = useRef<SigningLedgerNodeOffsets>({
    key: {},
    user: {},
  })

  const setSigningUserNodeRef = useCallback((id: string, node: HTMLElement | null) => {
    if (node) {
      signingUserNodeRefs.current.set(id, node)
    } else {
      signingUserNodeRefs.current.delete(id)
    }
  }, [])

  const setSigningKeyNodeRef = useCallback((id: string, node: HTMLElement | null) => {
    if (node) {
      signingKeyNodeRefs.current.set(id, node)
    } else {
      signingKeyNodeRefs.current.delete(id)
    }
  }, [])

  const setSigningRevisionNodeRef = useCallback((id: string, node: HTMLElement | null) => {
    if (node) {
      signingRevisionNodeRefs.current.set(id, node)
    } else {
      signingRevisionNodeRefs.current.delete(id)
    }
  }, [])

  const setAccountCardRef = useCallback((id: number, node: HTMLElement | null) => {
    if (node) {
      accountCardRefs.current.set(id, node)
    } else {
      accountCardRefs.current.delete(id)
    }
  }, [])

  const handleAdminTabSelect = useCallback((tab: AdminTabId) => {
    setActiveTab(tab)
    storeAdminTab(user.id, tab)
  }, [user.id])

  const getRevisionDocumentUrl = useCallback((
    documentId: number,
    revisionId: number,
  ) => {
    const params = new URLSearchParams({
      documentId: String(documentId),
      revisionId: String(revisionId),
    })

    return `${APP_DOCUMENTS_PATH}?${params.toString()}`
  }, [])

  useEffect(() => {
    if (!requestedAdminTab) {
      return
    }

    setActiveTab(requestedAdminTab)
    storeAdminTab(user.id, requestedAdminTab)
  }, [requestedAdminTab, user.id])

  useEffect(() => {
    if (!requestedAccountId || activeTab !== 'accounts') {
      return
    }

    let animationFrameId: number | null = null
    let timeoutId: number | null = null

    const scrollFrameId = window.requestAnimationFrame(() => {
      const accountCard = accountCardRefs.current.get(requestedAccountId)

      if (!accountCard) {
        return
      }

      accountCard.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
      })

      if (consumeAccountFocusToken(user.id, requestedAccountFocusToken)) {
        setHighlightedAccountId(null)

        animationFrameId = window.requestAnimationFrame(() => {
          setHighlightedAccountId(requestedAccountId)
          setHighlightToken((currentToken) => currentToken + 1)
        })

        timeoutId = window.setTimeout(() => {
          setHighlightedAccountId((currentAccountId) =>
            currentAccountId === requestedAccountId ? null : currentAccountId,
          )
        }, 2500)
      }
    })

    return () => {
      window.cancelAnimationFrame(scrollFrameId)
      if (animationFrameId !== null) {
        window.cancelAnimationFrame(animationFrameId)
      }
      if (timeoutId !== null) {
        window.clearTimeout(timeoutId)
      }
    }
  }, [activeTab, directory, requestedAccountFocusKey, requestedAccountFocusToken, requestedAccountId, user.id])

  const loadDirectory = useCallback(async () => {
    setIsDirectoryLoading(true)
    setDirectoryError(null)

    try {
      const response: AdminUserListResponse = await getAdminUsers()
      setDirectory(response.users)
      setRoleDraftByUserId(
        Object.fromEntries(
          response.users
            .filter((account) => ASSIGNABLE_ROLES.includes(account.role as AssignableUserRole))
            .map((account) => [account.id, account.role as AssignableUserRole]),
        ),
      )
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setDirectoryError(
        error instanceof Error ? error.message : 'Unable to load account directory.',
      )
    } finally {
      setIsDirectoryLoading(false)
    }
  }, [onSessionExpired])

  const loadSigningKey = useCallback(async () => {
    setIsSigningKeyLoading(true)
    setSigningKeyError(null)

    try {
      const response = await getVerificationPackageSigningKey()
      setSigningKey(response.signingKey)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSigningKeyError(
        error instanceof Error
          ? error.message
          : 'Unable to load verification package signing key.',
      )
    } finally {
      setIsSigningKeyLoading(false)
    }
  }, [onSessionExpired])

  const loadSigningLedger = useCallback(async () => {
    setIsSigningLedgerLoading(true)
    setSigningLedgerError(null)

    try {
      const response = await getSigningLedger()
      setSigningLedger(response.signers)
      setSigningLedgerDocuments(response.documents)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSigningLedgerError(
        error instanceof Error
          ? error.message
          : 'Unable to load signing trust ledger.',
      )
    } finally {
      setIsSigningLedgerLoading(false)
    }
  }, [onSessionExpired])

  const loadMigrantSigningLedger = useCallback(async () => {
    setIsMigrantSigningLoading(true)
    setMigrantSigningError(null)

    try {
      const response = await getMigrantSigningLedger()
      setMigrantSigningRegistrations(response.registrations)
      setMigrantSigningSigners(response.signers)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setMigrantSigningError(
        error instanceof Error
          ? error.message
          : 'Unable to load migrant signing trust ledger.',
      )
    } finally {
      setIsMigrantSigningLoading(false)
    }
  }, [onSessionExpired])

  const handleRoleUpdate = async (account: AdminUserSummary) => {
    const nextRole = roleDraftByUserId[account.id]

    if (!nextRole || nextRole === account.role) {
      return
    }

    const confirmed = window.confirm(
      `Change ${account.email} from ${getRoleLabel(account.role)} to ${getRoleLabel(nextRole)}?`,
    )

    if (!confirmed) {
      return
    }

    const reason = window.prompt('Optional audit reason for this role change.')

    setUpdatingRoleUserId(account.id)
    setRoleUpdateError(null)
    setRoleUpdateMessage('Confirm the role change with your admin security key.')

    try {
      const optionsResponse = await startAdminUserRoleUpdate(account.id, {
        reason: reason?.trim() || null,
        role: nextRole,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserRoleUpdate(account.id, assertion)
      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setRoleDraftByUserId((currentDrafts) => ({
        ...currentDrafts,
        [response.user.id]: response.user.role as AssignableUserRole,
      }))
      setRoleUpdateMessage(response.message)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setRoleUpdateError(
        error instanceof Error ? error.message : 'Unable to update user role.',
      )
    } finally {
      setUpdatingRoleUserId(null)
    }
  }

  const handleRecovery = async (
    account: AdminUserSummary,
    action: AdminUserRecoveryAction,
  ) => {
    const actionLabel = action === 'reset_totp'
      ? 'reset TOTP enrollment'
      : action === 'reset_password'
        ? 'issue a password reset link'
        : 'revoke all passkeys'
    const confirmed = window.confirm(
      `Authenticate to ${actionLabel} for ${account.email}? This will force the user through enrollment again.`,
    )

    if (!confirmed) {
      return
    }

    const reason = window.prompt(`Optional audit reason to ${actionLabel} for ${account.email}.`)

    setRecoveringUserId(account.id)
    setRecoveryAction(action)
    setRoleUpdateError(null)
    setPasswordResetLink(null)
    setRoleUpdateMessage('Confirm account recovery with your admin security key.')

    try {
      const optionsResponse = await startAdminUserRecovery(account.id, {
        action,
        reason: reason?.trim() || null,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserRecovery(account.id, assertion)
      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setRoleUpdateMessage(response.message)
      setPasswordResetLink(
        response.passwordReset
          ? new URL(response.passwordReset.resetPath, window.location.origin).toString()
          : null,
      )
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setRoleUpdateError(
        error instanceof Error ? error.message : 'Unable to complete account recovery.',
      )
    } finally {
      setRecoveringUserId(null)
      setRecoveryAction(null)
    }
  }

  const handleStatusUpdate = async (
    account: AdminUserSummary,
    action: AdminUserStatusAction,
  ) => {
    const actionLabel = action === 'suspend' ? 'suspend' : 'reactivate'
    let reason: string | null = null

    if (action === 'suspend') {
      const promptedReason = window.prompt(
        `Reason for suspending ${account.email}? This is stored in the audit trail and account directory.`,
      )

      if (promptedReason === null) {
        return
      }

      reason = promptedReason.trim() || null
    } else {
      const confirmed = window.confirm(`Authenticate to reactivate ${account.email}?`)

      if (!confirmed) {
        return
      }
    }

    setUpdatingStatusUserId(account.id)
    setStatusAction(action)
    setRoleUpdateError(null)
    setRoleUpdateMessage(`Confirm account ${actionLabel} with your admin security key.`)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startAdminUserStatusUpdate(account.id, {
        action,
        reason,
      })
      challengeIntentId = optionsResponse.challengeIntent?.id ?? null
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyAdminUserStatusUpdate(account.id, assertion)
      setDirectory((currentDirectory) =>
        currentDirectory.map((currentAccount) =>
          currentAccount.id === response.user.id ? response.user : currentAccount,
        ),
      )
      setRoleUpdateMessage(response.message)
    } catch (error) {
      if (
        challengeIntentId &&
        error instanceof DOMException &&
        error.name === 'NotAllowedError'
      ) {
        await cancelSecurityChallenge(challengeIntentId).catch(() => undefined)
      }

      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setRoleUpdateError(
        error instanceof Error ? error.message : 'Unable to update account status.',
      )
    } finally {
      setUpdatingStatusUserId(null)
      setStatusAction(null)
    }
  }

  const handleSigningKeyRotation = async () => {
    const isInitialGeneration = signingKey ? !signingKey.configured : false
    const defaultKeyId = signingKey?.keyId || getDefaultSigningKeyId()
    const actionLabel = isInitialGeneration ? 'generate' : 'rotate'
    const keyId = window.prompt(
      `${isInitialGeneration ? 'New' : 'Replacement'} package signing key id. Use a stable id that can be published with the fingerprint.`,
      defaultKeyId,
    )

    if (keyId === null) {
      return
    }

    const normalizedKeyId = keyId.trim()

    if (!/^[A-Za-z0-9._-]+$/.test(normalizedKeyId)) {
      setSigningKeyError('Key id can only contain letters, numbers, dots, underscores, and hyphens.')
      return
    }

    const reason = window.prompt(
      `Reason for ${actionLabel}ing the verification package signing key. This is stored in the audit trail.`,
      isInitialGeneration ? 'Initial verification package signing key generation' : undefined,
    )

    if (reason === null) {
      return
    }

    const normalizedReason = reason.trim()

    if (normalizedReason.length < 8) {
      setSigningKeyError('Rotation reason must be at least 8 characters.')
      return
    }

    const confirmed = window.confirm(
      isInitialGeneration
        ? 'Generate the verification package signing key? Newly exported packages will become server-signed.'
        : 'Rotate the verification package signing key? Newly exported packages will use the new fingerprint. Keep old fingerprints for old exports.',
    )

    if (!confirmed) {
      return
    }

    setIsRotatingSigningKey(true)
    setSigningKeyError(null)
    setSigningKeyMessage(`Confirm package signing key ${isInitialGeneration ? 'generation' : 'rotation'} with your admin security key.`)

    try {
      const optionsResponse = await startVerificationPackageSigningKeyRotation({
        bits: 3072,
        keyId: normalizedKeyId,
        reason: normalizedReason,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const response = await verifyVerificationPackageSigningKeyRotation(assertion)
      setSigningKey(response.signingKey)
      setSigningKeyMessage(response.message)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setSigningKeyError(
        error instanceof Error
          ? error.message
          : 'Unable to rotate verification package signing key.',
      )
    } finally {
      setIsRotatingSigningKey(false)
    }
  }

  useEffect(() => {
    void loadDirectory()
  }, [loadDirectory])

  useEffect(() => {
    void loadSigningKey()
  }, [loadSigningKey])

  useEffect(() => {
    void loadSigningLedger()
  }, [loadSigningLedger])

  useEffect(() => {
    if (activeTab === 'migrant-signing' && migrantSigningRegistrations.length === 0) {
      void loadMigrantSigningLedger()
    }
  }, [activeTab, loadMigrantSigningLedger, migrantSigningRegistrations.length])

  const signingLedgerGraph = useMemo(
    () => buildSigningLedgerGraph(signingLedgerDocuments, signingLedger),
    [signingLedgerDocuments, signingLedger],
  )
  const signingLedgerSignedEdges = useMemo(
    () => signingLedgerGraph.signerGroups.flatMap(({ edges }) => edges),
    [signingLedgerGraph],
  )

  useLayoutEffect(() => {
    if (activeTab !== 'signing') {
      setSigningLedgerConnectorPaths([])
      setSigningLedgerGraphBodyMinHeight(1)
      return
    }

    const graphBody = signingGraphBodyRef.current

    if (!graphBody) {
      setSigningLedgerConnectorPaths([])
      setSigningLedgerConnectorBounds({ height: 1, width: 1 })
      setSigningLedgerGraphBodyMinHeight(1)
      return
    }

    const getNodeBox = (node: HTMLElement, graphRect: DOMRect) => {
      const rect = node.getBoundingClientRect()
      const transform = window.getComputedStyle(node).transform
      const transformMatrix = transform === 'none' ? null : new DOMMatrixReadOnly(transform)
      const transformX = transformMatrix?.m41 ?? 0
      const transformY = transformMatrix?.m42 ?? 0

      return {
        centerY: rect.top + rect.height / 2 - graphRect.top - transformY,
        height: rect.height,
        left: rect.left - graphRect.left - transformX,
        right: rect.right - graphRect.left - transformX,
      }
    }

    const getPoint = (
      box: ReturnType<typeof getNodeBox>,
      side: 'left' | 'right',
      offset = 0,
    ) => ({
      x: side === 'left' ? box.left : box.right,
      y: box.centerY + offset,
    })

    const getCurve = (
      start: { x: number, y: number },
      end: { x: number, y: number },
    ) => {
      const handle = Math.max(36, Math.abs(end.x - start.x) * 0.5)

      return `M ${start.x} ${start.y} C ${start.x + handle} ${start.y} ${end.x - handle} ${end.y} ${end.x} ${end.y}`
    }

    const packNodeCenters = (
      items: Array<{
        box: ReturnType<typeof getNodeBox>
        id: string
        targetCenter: number
      }>,
    ) => {
      const packedCenters = new Map<string, number>()
      const nodeGap = 12
      const sortedItems = [...items].sort((first, second) => first.targetCenter - second.targetCenter)
      const clusters: typeof sortedItems[] = []

      sortedItems.forEach((item) => {
        const currentCluster = clusters.at(-1)
        const previousItem = currentCluster?.at(-1)

        if (
          !currentCluster ||
          !previousItem ||
          item.targetCenter - previousItem.targetCenter >
            previousItem.box.height / 2 + nodeGap + item.box.height / 2
        ) {
          clusters.push([item])
          return
        }

        currentCluster.push(item)
      })

      clusters.forEach((cluster) => {
        const totalHeight = cluster.reduce((total, item) => total + item.box.height, 0) +
          Math.max(0, cluster.length - 1) * nodeGap
        const targetCenter = cluster.reduce((total, item) => total + item.targetCenter, 0) / cluster.length
        let nextTop = Math.max(0, targetCenter - totalHeight / 2)

        cluster.forEach((item) => {
          const center = nextTop + item.box.height / 2
          packedCenters.set(item.id, center)
          nextTop += item.box.height + nodeGap
        })
      })

      return packedCenters
    }

    const animationFrames = new Set<number>()
    const timeouts = new Set<number>()
    let scheduleCalculatePaths: () => void = () => undefined

    const calculatePaths = () => {
      const graphRect = graphBody.getBoundingClientRect()
      const nextPaths: SigningLedgerConnectorPath[] = []
      const keyBoxes = new Map<string, ReturnType<typeof getNodeBox>>()
      const userBoxes = new Map<string, ReturnType<typeof getNodeBox>>()
      const revisionBoxes = new Map<string, ReturnType<typeof getNodeBox>>()
      const currentOffsets = signingLedgerNodeOffsetsRef.current
      const nextOffsets: SigningLedgerNodeOffsets = {
        key: {},
        user: {},
      }

      signingLedgerGraph.signerGroups.forEach(({ signer }) => {
        const keyNode = signingKeyNodeRefs.current.get(String(signer.id))

        if (keyNode) {
          keyBoxes.set(String(signer.id), getNodeBox(keyNode, graphRect))
        }
      })

      signingLedgerSignedEdges.forEach((edge) => {
        const revisionNode = signingRevisionNodeRefs.current.get(`${edge.document.id}:${edge.revision.id}`)

        if (!revisionNode) {
          return
        }

        revisionBoxes.set(
          `${edge.document.id}:${edge.revision.id}`,
          getNodeBox(revisionNode, graphRect),
        )
      })

      signingLedgerGraph.signerGroups.forEach(({ signer }) => {
        const userNode = signingUserNodeRefs.current.get(String(signer.id))

        if (userNode) {
          userBoxes.set(String(signer.id), getNodeBox(userNode, graphRect))
        }
      })

      signingLedgerGraph.unsignedSigners.forEach((signer) => {
        const userNode = signingUserNodeRefs.current.get(String(signer.id))

        if (userNode) {
          userBoxes.set(String(signer.id), getNodeBox(userNode, graphRect))
        }
      })

      const signerTargetCenters = signingLedgerGraph.signerGroups.flatMap(({ edges, signer }) => {
        const signerId = String(signer.id)
        const userBox = userBoxes.get(signerId)
        const keyBox = keyBoxes.get(signerId)
        const ownedRevisionCenters = edges
          .map((edge) => {
            const revisionBox = revisionBoxes.get(`${edge.document.id}:${edge.revision.id}`)

            return revisionBox?.centerY ?? null
          })
          .filter((center): center is number => center !== null)

        if (!userBox || !keyBox || ownedRevisionCenters.length === 0) {
          return []
        }

        const averageRevisionCenter = ownedRevisionCenters.reduce((total, center) => total + center, 0) / ownedRevisionCenters.length
        return [{
          id: signerId,
          targetCenter: averageRevisionCenter,
        }]
      })
      const userTargetCenters = signerTargetCenters

      const packedUserCenters = packNodeCenters(userTargetCenters.flatMap((item) => {
        const box = userBoxes.get(item.id)

        return box ? [{ ...item, box }] : []
      }))
      const packedKeyCenters = packNodeCenters(signerTargetCenters.flatMap((item) => {
        const box = keyBoxes.get(item.id)

        return box ? [{ ...item, box }] : []
      }))

      userTargetCenters.forEach(({ id }) => {
        const userBox = userBoxes.get(id)
        const userCenter = packedUserCenters.get(id)

        if (userBox && userCenter !== undefined) {
          nextOffsets.user[id] = Math.round(userCenter - userBox.centerY)
        }
      })

      signerTargetCenters.forEach(({ id }) => {
        const keyBox = keyBoxes.get(id)
        const keyCenter = packedKeyCenters.get(id)

        if (keyBox && keyCenter !== undefined) {
          nextOffsets.key[id] = Math.round(keyCenter - keyBox.centerY)
        }
      })

      const transformedNodeBottoms = [
        ...Array.from(userBoxes.entries()).map(([id, box]) =>
          box.centerY + (nextOffsets.user[id] ?? 0) + box.height / 2,
        ),
        ...Array.from(keyBoxes.entries()).map(([id, box]) =>
          box.centerY + (nextOffsets.key[id] ?? 0) + box.height / 2,
        ),
        ...Array.from(revisionBoxes.values()).map((box) => box.centerY + box.height / 2),
      ]
      const graphHeight = Math.max(
        1,
        graphBody.scrollHeight,
        graphRect.height,
        ...transformedNodeBottoms.map((bottom) => Math.ceil(bottom + 8)),
      )

      setSigningLedgerGraphBodyMinHeight(graphHeight)
      setSigningLedgerConnectorBounds({
        height: graphHeight,
        width: Math.max(1, graphBody.scrollWidth, graphRect.width),
      })

      signingLedgerGraph.signerGroups.forEach(({ signer }) => {
        const userBox = userBoxes.get(String(signer.id))
        const keyBox = keyBoxes.get(String(signer.id))

        if (!userBox || !keyBox) {
          return
        }

        const userOffset = nextOffsets.user[String(signer.id)] ?? 0
        const keyOffset = nextOffsets.key[String(signer.id)] ?? 0

        nextPaths.push({
          id: `user-key:${signer.id}`,
          variant: 'user-key',
          d: getCurve(
            getPoint(userBox, 'right', userOffset),
            getPoint(keyBox, 'left', keyOffset),
          ),
        })
      })

      signingLedgerSignedEdges.forEach((edge) => {
        if (!edge.signer || !edge.signature) {
          return
        }

        const keyBox = keyBoxes.get(String(edge.signer.id))
        const revisionBox = revisionBoxes.get(`${edge.document.id}:${edge.revision.id}`)

        if (!keyBox || !revisionBox) {
          return
        }

        const keyOffset = nextOffsets.key[String(edge.signer.id)] ?? 0

        nextPaths.push({
          id: `key-revision:${edge.id}`,
          variant: 'key-revision',
          d: getCurve(
            getPoint(keyBox, 'right', keyOffset),
            getPoint(revisionBox, 'left'),
          ),
        })
      })

      if (!areSigningLedgerNodeOffsetsEqual(currentOffsets, nextOffsets)) {
        signingLedgerNodeOffsetsRef.current = nextOffsets
        setSigningLedgerNodeOffsets(nextOffsets)
      }

      setSigningLedgerConnectorPaths(nextPaths)
    }

    scheduleCalculatePaths = () => {
      const animationFrame = window.requestAnimationFrame(() => {
        animationFrames.delete(animationFrame)
        calculatePaths()
      })
      animationFrames.add(animationFrame)
    }

    const scheduleDeferredCalculatePaths = (delay: number) => {
      const timeout = window.setTimeout(() => {
        timeouts.delete(timeout)
        scheduleCalculatePaths()
      }, delay)
      timeouts.add(timeout)
    }

    scheduleCalculatePaths()
    scheduleDeferredCalculatePaths(0)
    scheduleDeferredCalculatePaths(120)
    const resizeObserver = new ResizeObserver(calculatePaths)
    resizeObserver.observe(graphBody)
    signingUserNodeRefs.current.forEach((node) => resizeObserver.observe(node))
    signingKeyNodeRefs.current.forEach((node) => resizeObserver.observe(node))
    signingRevisionNodeRefs.current.forEach((node) => resizeObserver.observe(node))
    window.addEventListener('resize', calculatePaths)

    return () => {
      animationFrames.forEach((animationFrame) => window.cancelAnimationFrame(animationFrame))
      timeouts.forEach((timeout) => window.clearTimeout(timeout))
      resizeObserver.disconnect()
      window.removeEventListener('resize', calculatePaths)
    }
  }, [activeTab, signingLedgerGraph, signingLedgerSignedEdges])

  return (
    <section className="workspace-stack">
      <section className="workspace-panel">
        <h2 className="workspace-panel__title">Admin-only module</h2>
        <p className="workspace-panel__copy">
          This route remains restricted to admins while the rest of the shell becomes role-aware.
        </p>
        <ul className="route-checklist">
          <li>Name: {user.name}</li>
          <li>Email: {user.email}</li>
          <li>
            Role: <RoleBadge role={user.role} />
          </li>
        </ul>
      </section>

      <nav className="admin-tabs" aria-label="Admin panel sections">
        {ADMIN_TABS.map((tab) => (
          <button
            aria-current={activeTab === tab.id ? 'page' : undefined}
            className={`admin-tabs__button${activeTab === tab.id ? ' admin-tabs__button--active' : ''}`}
            key={tab.id}
            onClick={() => handleAdminTabSelect(tab.id)}
            type="button"
          >
            <span>{tab.label}</span>
            <small>{tab.copy}</small>
          </button>
        ))}
      </nav>

      {activeTab === 'system' ? (
        <section className="workspace-panel workspace-panel--accent">
          <h2 className="workspace-panel__title">System Configuration</h2>
          <p className="workspace-panel__copy">
            Privileged settings, including invite creation and redemption policy, should stay explicit,
            audited, and passkey-gated before becoming mutable from the browser.
          </p>

          <div className="admin-surface-grid">
            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="verify" />
              </span>
              <div>
                <h3>Signature policy</h3>
                <p>Signature validity duration, verification package export rules, and future manifest trust history.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="key" />
              </span>
              <div>
                <h3>Authentication policy</h3>
                <p>Coordinator/admin passkey requirements, TOTP enforcement, session limits, and recovery constraints.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="upload" />
              </span>
              <div>
                <h3>Document policy</h3>
                <p>Allowed file types, maximum upload size, retention rules, and irreversible deletion controls.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="admin" />
              </span>
              <div>
                <h3>Admin safeguards</h3>
                <p>Future two-person rules, admin creation restrictions, and sensitive-setting approval windows.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="invite" />
              </span>
              <div>
                <h3>Invite policy</h3>
                <p>Allowed invite roles, expiration windows, single-use redemption, email binding, and invite audit alerts.</p>
              </div>
            </article>
          </div>
        </section>
      ) : null}

      {activeTab === 'audit' ? (
        <section className="workspace-panel workspace-panel--accent">
          <h2 className="workspace-panel__title">Audit Overview</h2>
          <p className="workspace-panel__copy">
            Summary cards should complement the full Logging module instead of
            duplicating the raw event feed.
          </p>

          <div className="admin-surface-grid">
            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="admin" />
              </span>
              <div>
                <h3>Privileged changes</h3>
                <p>Recent role updates, suspensions, recovery actions, and system configuration changes.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="key" />
              </span>
              <div>
                <h3>Authentication risk</h3>
                <p>Failed login spikes, passkey challenge failures, missing enrollment, and suspicious recovery activity.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="document" />
              </span>
              <div>
                <h3>Document trust</h3>
                <p>Unsigned revisions, expired signatures, verification-package exports, and signing activity.</p>
              </div>
            </article>

            <article className="admin-surface-card">
              <span className="admin-surface-card__icon">
                <AppIcon name="verify" />
              </span>
              <div>
                <h3>Invite lifecycle</h3>
                <p>Created, verified, issued, redeemed, expired, and revoked invitation activity.</p>
              </div>
            </article>
          </div>
        </section>
      ) : null}

      {activeTab === 'security' ? (
        <section className="workspace-panel" aria-label="Verification package signing key">
          <div className="admin-directory-toolbar">
            <div>
              <h2 className="workspace-panel__title">Security Keys</h2>
              <p className="workspace-panel__copy">
                Published trust anchors and rotation controls for verification
                packages. Compare the fingerprint with external review evidence.
              </p>
            </div>
            <button
              className="audit-toolbar__button"
              disabled={isSigningKeyLoading}
              onClick={() => void loadSigningKey()}
              type="button"
            >
              <AppIcon name="refresh" size={18} />
              {isSigningKeyLoading ? 'Refreshing...' : 'Refresh key'}
            </button>
          </div>

          {signingKeyError ? (
            <div className="auth-feedback auth-feedback--error">
              <span aria-hidden="true" />
              <p>{signingKeyError}</p>
            </div>
          ) : null}

          {signingKeyMessage ? (
            <div className="auth-feedback auth-feedback--success">
              <span aria-hidden="true" />
              <p>{signingKeyMessage}</p>
            </div>
          ) : null}

          {signingKey ? (
            <div className="admin-signing-key">
              <div className="admin-signing-key__facts">
                <span>
                  <small>Status</small>
                  <strong>{signingKey.configured ? 'Configured' : 'No signing key'}</strong>
                </span>
                <span>
                  <small>Key ID</small>
                  <strong>{signingKey.keyId || 'Not configured'}</strong>
                </span>
                <span>
                  <small>Algorithm</small>
                  <strong>{signingKey.algorithm}</strong>
                </span>
                <span>
                  <small>Rotation</small>
                  <strong>{signingKey.rotationSupported ? 'Available' : 'Manual only'}</strong>
                </span>
              </div>

              <div className="admin-signing-key__fingerprint">
                <small>Public key SHA-256 fingerprint</small>
                <code>{formatFingerprint(signingKey.publicKeyFingerprint)}</code>
              </div>

              <ul className="admin-user-enrollment" aria-label="Package signing key state">
                <li className={signingKey.privateKeyConfigured ? 'is-complete' : 'is-missing'}>
                  Private key {signingKey.privateKeyConfigured ? 'configured' : 'missing'}
                </li>
                <li className={signingKey.publicKeyConfigured ? 'is-complete' : 'is-missing'}>
                  Public key {signingKey.publicKeyConfigured ? 'configured' : 'missing'}
                </li>
                <li className={signingKey.envWritable ? 'is-complete' : 'is-missing'}>
                  .env {signingKey.envWritable ? 'writable' : 'not writable'}
                </li>
                <li className={signingKey.configCached ? 'is-missing' : 'is-complete'}>
                  Config {signingKey.configCached ? 'cached' : 'live'}
                </li>
              </ul>

              <div className="admin-signing-key__actions">
                <button
                  className="admin-role-assignment__button"
                  disabled={!signingKey.rotationSupported || isRotatingSigningKey}
                  onClick={() => void handleSigningKeyRotation()}
                  type="button"
                >
                  <AppIcon name="key" size={17} />
                  {isRotatingSigningKey
                    ? 'Authenticating...'
                    : signingKey.configured
                      ? 'Rotate key'
                      : 'Generate key'}
                </button>
                <p className="admin-role-assignment__note">
                  {signingKey.configured
                    ? 'Rotation is passkey-gated and writes a new keypair to '
                    : 'Generation is passkey-gated and writes the first keypair to '}
                  <code>.env</code>
                  {signingKey.configured
                    ? '. Keep old fingerprints for previously exported packages.'
                    : '. Exported packages will stay unsigned until this key exists.'}
                </p>
              </div>

              {!signingKey.configured && !signingKey.rotationSupported ? (
                <div className="admin-signing-key__manual">
                  <strong>Manual provisioning required</strong>
                  <p>
                    The app process cannot write <code>.env</code>, so generate the
                    first package signing key from the API checkout and then clear config.
                  </p>
                  <pre><code>{getSigningKeyProvisionCommand(signingKey.keyId)}</code></pre>
                  <pre><code>php artisan optimize:clear</code></pre>
                </div>
              ) : null}
            </div>
          ) : (
            <p className="workspace-panel__copy">Loading package signing key...</p>
          )}
        </section>
      ) : null}

      {activeTab === 'signing' ? (
        <section className="workspace-panel" aria-label="Signing trust ledger">
          <div className="admin-directory-toolbar">
            <div>
              <h2 className="workspace-panel__title">Signing Trust</h2>
              <p className="workspace-panel__copy">
                Trace each signing-capable user to their document signatures, grouped by document and revision.
              </p>
            </div>
            <button
              className="audit-toolbar__button"
              disabled={isSigningLedgerLoading}
              onClick={() => void loadSigningLedger()}
              type="button"
            >
              <AppIcon name="refresh" size={18} />
              {isSigningLedgerLoading ? 'Refreshing...' : 'Refresh ledger'}
            </button>
          </div>

          {signingLedgerError ? (
            <div className="auth-feedback auth-feedback--error">
              <span aria-hidden="true" />
              <p>{signingLedgerError}</p>
            </div>
          ) : null}

          {isSigningLedgerLoading && signingLedger.length === 0 && signingLedgerDocuments.length === 0 ? (
            <p className="workspace-panel__copy">Loading signing ledger...</p>
          ) : null}

          {!isSigningLedgerLoading && signingLedger.length === 0 && signingLedgerDocuments.length === 0 && !signingLedgerError ? (
            <p className="workspace-panel__copy">No signing ledger records found yet.</p>
          ) : null}

          {signingLedger.length > 0 || signingLedgerDocuments.length > 0 ? (
            <div className="signing-ledger">
              <div className="signing-ledger__graph-scroll" aria-label="Scrollable signing trust graph" tabIndex={0}>
                <article className="signing-ledger__graph">
                  <div className="signing-ledger__graph-head" aria-hidden="true">
                    <span>User</span>
                    <span>Keys</span>
                    <span>Document revisions</span>
                  </div>

                  <div
                    className="signing-ledger__graph-body signing-ledger__graph-body--indexed"
                    ref={signingGraphBodyRef}
                    style={{
                      minHeight: `${signingLedgerGraphBodyMinHeight}px`,
                    }}
                  >
                    <svg
                      aria-hidden="true"
                      className="signing-ledger__connector-layer"
                      style={{
                        height: `${signingLedgerConnectorBounds.height}px`,
                        width: `${signingLedgerConnectorBounds.width}px`,
                      }}
                      viewBox={`0 0 ${signingLedgerConnectorBounds.width} ${signingLedgerConnectorBounds.height}`}
                    >
                      {signingLedgerConnectorPaths.map((path) => (
                        <path
                          className={`signing-ledger__connector signing-ledger__connector--${path.variant}`}
                          data-connector-id={path.id}
                          data-connector-variant={path.variant}
                          d={path.d}
                          key={path.id}
                        />
                      ))}
                    </svg>
                    <section className="signing-ledger__user-column" aria-label="Signing users">
                      {signingLedgerGraph.signerGroups.map(({ signer }) => (
                        <section
                          className="signing-ledger__user-node"
                          aria-label={`${signer.name} signer`}
                          data-signer-id={signer.id}
                          key={signer.id}
                          ref={(node) => setSigningUserNodeRef(String(signer.id), node)}
                          style={{
                            '--signing-node-offset': `${signingLedgerNodeOffsets.user[String(signer.id)] ?? 0}px`,
                          } as CSSProperties}
                        >
                          <span className="signing-ledger__node-icon">
                            <AppIcon name="admin" size={17} />
                          </span>
                          <div>
                            <h3>{signer.name}</h3>
                            <p>{signer.email}</p>
                          </div>
                          <div className="signing-ledger__signer-badges">
                            <RoleBadge role={signer.role} />
                            <span>{signer.signatureCount} signatures</span>
                          </div>
                        </section>
                      ))}

                    </section>

                  <section className="signing-ledger__key-column" aria-label="Signature keys">
                      {signingLedgerGraph.signerGroups.map(({ edges, signer }) => {
                        const keyIdentifier = getSigningKeyIdentifier(edges)

                        return (
                          <span
                            className="signing-ledger__key-node"
                            data-signer-id={signer.id}
                            key={signer.id}
                            ref={(node) => setSigningKeyNodeRef(String(signer.id), node)}
                            style={{
                              '--signing-node-offset': `${signingLedgerNodeOffsets.key[String(signer.id)] ?? 0}px`,
                            } as CSSProperties}
                          >
                            <strong>{signer.name} security key</strong>
                            <span>passkey</span>
                            <code>{signer.email}</code>
                            <small title={keyIdentifier.title}>key id {keyIdentifier.label}</small>
                            <small>{edges.length} signed revisions</small>
                          </span>
                        )
                      })}
                  </section>

                    <section className="signing-ledger__document-column" aria-label="Document revisions">
                      {signingLedgerGraph.documentGroups.map((documentGroup) => (
                        <article className="signing-ledger__document-group" key={documentGroup.id}>
                          <header className="signing-ledger__document-title">
                            <AppIcon name="document" size={18} />
                            <div>
                              <h4>{documentGroup.document.title}</h4>
                              <p>Document #{documentGroup.document.id} · {documentGroup.document.status}</p>
                            </div>
                          </header>

                          <div className="signing-ledger__revision-stack">
                            {documentGroup.revisions.map((revisionGroup) => (
                              <details
                                className="signing-ledger__revision-node"
                                data-revision-id={revisionGroup.id}
                                key={revisionGroup.id}
                                ref={(node) => setSigningRevisionNodeRef(revisionGroup.id, node)}
                              >
                                <summary>
                                  <strong>Revision {revisionGroup.revision.revisionNumber}</strong>
                                  <a
                                    className="signing-ledger__revision-link"
                                    href={getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id)}
                                    onClick={(event) => {
                                      event.stopPropagation()
                                      if (!onNavigate) {
                                        return
                                      }

                                      event.preventDefault()
                                      onNavigate(getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id))
                                    }}
                                  >
                                    Open
                                  </a>
                                </summary>
                                <div className="signing-ledger__revision-details">
                                  <span>{revisionGroup.revision.originalFileName || 'No file name'}</span>
                                  <code>{revisionGroup.revision.sha256?.slice(0, 16) || 'No hash'}</code>
                                  <div className="signing-ledger__revision-meta">
                                    {revisionGroup.edges.map((revisionEdge) => (
                                      <span key={revisionEdge.id}>
                                        {revisionEdge.signer
                                          ? `${revisionEdge.signer.name} · Signed ${formatOptionalDate(revisionEdge.signature?.signedAt)} · Expires ${formatOptionalDate(revisionEdge.signature?.expiresAt)}`
                                          : `Unsigned · ${revisionEdge.revision.signatureStatus}`}
                                      </span>
                                    ))}
                                  </div>
                                </div>
                              </details>
                            ))}
                          </div>
                        </article>
                      ))}
                    </section>
                  </div>
                </article>
              </div>

              {signingLedgerGraph.unsignedSigners.length > 0 ? (
                <section className="signing-ledger__unsigned" aria-label="Signing users without signatures">
                  <header className="signing-ledger__unsigned-header">
                    <h3>Signing-capable users without signatures</h3>
                    <p>These accounts can sign after enrollment and permissions are complete, but have no signed revisions yet.</p>
                  </header>
                  <div className="signing-ledger__unsigned-list">
                    {signingLedgerGraph.unsignedSigners.map((signer) => (
                      <article className="signing-ledger__unsigned-user" key={signer.id}>
                        <span className="signing-ledger__node-icon">
                          <AppIcon name="admin" size={17} />
                        </span>
                        <div>
                          <h4>{signer.name}</h4>
                          <p>{signer.email}</p>
                        </div>
                        <div className="signing-ledger__signer-badges">
                          <RoleBadge role={signer.role} />
                          <span>{signer.signatureCount} signatures</span>
                        </div>
                      </article>
                    ))}
                  </div>
                </section>
              ) : null}

              <section className="signing-ledger__compact" aria-label="Compact signing trust ledger">
                {signingLedgerGraph.documentGroups.map((documentGroup) => (
                  <article className="signing-ledger__compact-document" key={documentGroup.id}>
                    <header className="signing-ledger__compact-title">
                      <AppIcon name="document" size={18} />
                      <div>
                        <h3>{documentGroup.document.title}</h3>
                        <p>Document #{documentGroup.document.id} · {documentGroup.document.status}</p>
                      </div>
                    </header>

                    <div className="signing-ledger__compact-revisions">
                      {documentGroup.revisions.map((revisionGroup) => (
                        <details className="signing-ledger__compact-revision" key={revisionGroup.id}>
                          <summary>
                            <strong>Revision {revisionGroup.revision.revisionNumber}</strong>
                            <a
                              className="signing-ledger__revision-link"
                              href={getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id)}
                              onClick={(event) => {
                                event.stopPropagation()
                                if (!onNavigate) {
                                  return
                                }

                                event.preventDefault()
                                onNavigate(getRevisionDocumentUrl(documentGroup.document.id, revisionGroup.revision.id))
                              }}
                            >
                              Open
                            </a>
                          </summary>
                          <div className="signing-ledger__compact-revision-details">
                            <span>{revisionGroup.revision.originalFileName || 'No file name'}</span>
                            <code>{revisionGroup.revision.sha256?.slice(0, 16) || 'No hash'}</code>
                            <div className="signing-ledger__compact-signatures">
                              {revisionGroup.edges.map((revisionEdge) => (
                                <span
                                  className={`signing-ledger__compact-signature${revisionEdge.signer ? '' : ' signing-ledger__compact-signature--unsigned'}`}
                                  key={revisionEdge.id}
                                >
                                  {revisionEdge.signer ? (
                                    <>
                                      <strong>{revisionEdge.signer.name}</strong>
                                      <small>{revisionEdge.signer.email}</small>
                                      <small>Signed {formatOptionalDate(revisionEdge.signature?.signedAt)} · Expires {formatOptionalDate(revisionEdge.signature?.expiresAt)}</small>
                                    </>
                                  ) : (
                                    <>
                                      <strong>Unsigned</strong>
                                      <small>{revisionEdge.revision.signatureStatus}</small>
                                    </>
                                  )}
                                </span>
                              ))}
                            </div>
                          </div>
                        </details>
                      ))}
                    </div>
                  </article>
                ))}
              </section>
            </div>
          ) : null}
        </section>
      ) : null}

      {activeTab === 'migrant-signing' ? (
        <section className="workspace-panel" aria-label="Migrant signing trust ledger">
          <div className="admin-directory-toolbar">
            <div>
              <h2 className="workspace-panel__title">Migrant Signing Trust</h2>
              <p className="workspace-panel__copy">
                Trace registration submissions, reviews, and decisions to the user and passkey that signed each action.
              </p>
            </div>
            <button
              className="audit-toolbar__button"
              disabled={isMigrantSigningLoading}
              onClick={() => void loadMigrantSigningLedger()}
              type="button"
            >
              <AppIcon name="refresh" size={18} />
              {isMigrantSigningLoading ? 'Refreshing...' : 'Refresh ledger'}
            </button>
          </div>

          {migrantSigningError ? (
            <div className="auth-feedback auth-feedback--error">
              <span aria-hidden="true" />
              <p>{migrantSigningError}</p>
            </div>
          ) : null}

          {isMigrantSigningLoading && migrantSigningRegistrations.length === 0 ? (
            <p className="workspace-panel__copy">Loading migrant signing ledger...</p>
          ) : null}

          {!isMigrantSigningLoading && migrantSigningRegistrations.length === 0 && !migrantSigningError ? (
            <p className="workspace-panel__copy">No migrant registrations found yet.</p>
          ) : null}

          {migrantSigningRegistrations.length > 0 ? (
            <div className="migrant-signing-ledger">
              <section className="migrant-signing-ledger__signers" aria-label="Migrant registration signers">
                {migrantSigningSigners.map((signer) => (
                  <article className="migrant-signing-ledger__signer" key={signer.id}>
                    <span className="signing-ledger__node-icon">
                      <AppIcon name="admin" size={17} />
                    </span>
                    <div>
                      <strong>{signer.name}</strong>
                      <small>{signer.email}</small>
                    </div>
                    <div className="signing-ledger__signer-badges">
                      <RoleBadge role={signer.role} />
                      <span>{signer.signatureCount} signatures</span>
                    </div>
                  </article>
                ))}
              </section>

              <section className="migrant-signing-ledger__registrations" aria-label="Signed migrant registrations">
                {migrantSigningRegistrations.map((registration) => (
                  <details className={`migrant-signing-ledger__registration${registration.isPurged ? ' migrant-signing-ledger__registration--purged' : ''}`} key={registration.id}>
                    <summary>
                      <span className="migrant-signing-ledger__registration-title">
                        <AppIcon name="sign" size={18} />
                        <span>
                          <strong>{registration.fullName}</strong>
                          <small>Registration #{registration.id} · {formatLedgerLabel(registration.status)}</small>
                        </span>
                      </span>
                      <span className="migrant-signing-ledger__signature-count">
                        {registration.isPurged ? <small>Purged {formatOptionalDate(registration.purgedAt)}</small> : null}
                        {registration.signatures.length} signatures
                      </span>
                    </summary>

                    <div className="migrant-signing-ledger__chain">
                      {registration.signatures.length > 0 ? registration.signatures.map((signature) => (
                        <article className="migrant-signing-ledger__signature" key={signature.id}>
                          <span className="migrant-signing-ledger__action">{formatLedgerLabel(signature.actionType)}</span>
                          <div className="migrant-signing-ledger__actor">
                            <strong>{signature.actor?.name ?? 'Unknown signer'}</strong>
                            <small>{signature.actor?.email ?? signature.actor?.role ?? 'Account unavailable'}</small>
                            {signature.actor ? <RoleBadge role={signature.actor.role} /> : null}
                          </div>
                          <div className="migrant-signing-ledger__key">
                            <span><AppIcon name="key" size={15} /> {signature.algorithm}</span>
                            <code title={signature.publicKeyRef ?? undefined}>
                              {signature.publicKeyRef ? signature.publicKeyRef.slice(0, 24) : 'Key reference unavailable'}
                            </code>
                          </div>
                          <time>{formatOptionalDate(signature.verifiedAt)}</time>
                        </article>
                      )) : (
                        <p className="workspace-panel__copy">This registration has no recorded signatures.</p>
                      )}
                    </div>
                  </details>
                ))}
              </section>
            </div>
          ) : null}
        </section>
      ) : null}

      {activeTab === 'accounts' ? (
        <>
          <section className="workspace-panel workspace-panel--account-directory" aria-label="Account directory">
            <div className="admin-directory-toolbar">
          <div>
            <h2 className="workspace-panel__title">Account directory</h2>
            <p className="workspace-panel__copy">
              Role, enrollment, last sign-in, and status visibility for current accounts.
            </p>
          </div>
          <button
            className="audit-toolbar__button"
            disabled={isDirectoryLoading}
            onClick={() => void loadDirectory()}
            type="button"
          >
            <AppIcon name="refresh" size={18} />
            {isDirectoryLoading ? 'Refreshing...' : 'Refresh users'}
          </button>
        </div>

        {directoryError ? (
          <div className="auth-feedback auth-feedback--error">
            <span aria-hidden="true" />
            <p>{directoryError}</p>
          </div>
        ) : null}

        {roleUpdateError ? (
          <div className="auth-feedback auth-feedback--error">
            <span aria-hidden="true" />
            <p>{roleUpdateError}</p>
          </div>
        ) : null}

                {roleUpdateMessage ? (
                  <div className="auth-feedback auth-feedback--success">
                    <span aria-hidden="true" />
                    <div>
                      <p>{roleUpdateMessage}</p>
                      {passwordResetLink ? (
                        <p>
                          Password reset link:{' '}
                          <a href={passwordResetLink}>{passwordResetLink}</a>
                        </p>
                      ) : null}
                    </div>
                  </div>
                ) : null}

        {isDirectoryLoading && directory.length === 0 ? (
          <p className="workspace-panel__copy">Loading account directory...</p>
        ) : null}

        {!isDirectoryLoading && directory.length === 0 && !directoryError ? (
          <p className="workspace-panel__copy">No accounts found yet.</p>
        ) : null}

        <div className="admin-user-list">
          {directory.map((account) => (
            <article
              className={`admin-user-card${highlightedAccountId === account.id ? ' admin-user-card--highlighted' : ''}`}
              key={`${account.id}:${highlightedAccountId === account.id ? highlightToken : 0}`}
              ref={(node) => setAccountCardRef(account.id, node)}
            >
              <header className="admin-user-card__header">
                <div>
                  <h3>{account.name}</h3>
                  <p>{account.email}</p>
                </div>
                <span
                  className={`admin-user-status admin-user-status--${account.status === 'active' ? 'active' : 'suspended'}`}
                >
                  {account.status}
                </span>
              </header>

              <div className="admin-user-card__body">
                <div className="admin-user-facts">
                  <span>
                    <small>Role</small>
                    <RoleBadge role={account.role} />
                  </span>
                  <span>
                    <small>Devices</small>
                    <strong>{account.devices.count}</strong>
                  </span>
                  <span>
                    <small>Last sign-in</small>
                    <strong>{formatOptionalDate(account.lastSignInAt)}</strong>
                  </span>
                </div>

                <ul className="admin-user-enrollment" aria-label={`${account.name} enrollment state`}>
                  <li className={account.enrollment.isFullyEnrolled ? 'is-complete' : 'is-missing'}>
                    <strong>{getEnrollmentLabel(account)}</strong>
                    <span>
                      TOTP {account.enrollment.enrolled.totp ? 'enabled' : 'missing'} · {account.enrollment.passkeyCount}{' '}
                      {account.enrollment.passkeyCount === 1 ? 'passkey' : 'passkeys'}
                    </span>
                  </li>
                  {account.status !== 'active' ? (
                    <li>
                      Suspended {formatOptionalDate(account.suspendedAt)}
                    </li>
                  ) : null}
                </ul>

                <div className="admin-user-devices">
                  <small>Recent devices</small>
                  {account.devices.recent.length > 0 ? (
                    <ul aria-label={`${account.name} recent devices`}>
                      {account.devices.recent.map((device) => (
                        <li key={device.id}>
                          <strong>{device.alias || 'Unknown browser'}</strong>
                          <span>#{device.deviceId}</span>
                          <span>{formatOptionalDate(device.lastLoginAt)}</span>
                        </li>
                      ))}
                    </ul>
                  ) : (
                    <p>No remembered devices yet.</p>
                  )}
                </div>
              </div>

              <div className="admin-user-actions">
                <div className="admin-role-assignment">
                  <div>
                    <label htmlFor={`role-${account.id}`}>Assign role</label>
                    <select
                      disabled={account.role === 'admin' || account.id === user.id || updatingRoleUserId === account.id}
                      id={`role-${account.id}`}
                      onChange={(event) => {
                        setRoleDraftByUserId((currentDrafts) => ({
                          ...currentDrafts,
                          [account.id]: event.target.value as AssignableUserRole,
                        }))
                      }}
                      value={getRoleSelectValue(account, roleDraftByUserId)}
                    >
                      {account.role === 'admin' ? (
                        <option value="admin">Admin</option>
                      ) : null}
                      {ASSIGNABLE_ROLES.map((role) => (
                        <option key={role} value={role}>
                          {getRoleLabel(role)}
                        </option>
                      ))}
                    </select>
                  </div>
                  <button
                    className="admin-role-assignment__button"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      updatingRoleUserId === account.id ||
                      isCoordinatorPromotionBlocked(account, roleDraftByUserId) ||
                      roleDraftByUserId[account.id] === account.role
                    }
                    onClick={() => void handleRoleUpdate(account)}
                    type="button"
                  >
                    <AppIcon name="verify" size={17} />
                    {updatingRoleUserId === account.id ? 'Authenticating...' : 'Apply role'}
                  </button>
                </div>
                {isCoordinatorPromotionBlocked(account, roleDraftByUserId) ? (
                  <p className="admin-role-assignment__note admin-role-assignment__note--warning">
                    Coordinator promotion requires the target user to register a passkey first.
                  </p>
                ) : null}

                <div className="admin-recovery-actions" aria-label={`${account.name} recovery and status controls`}>
                  <button
                    className="admin-recovery-actions__button"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      recoveringUserId === account.id ||
                      !account.enrollment.enrolled.totp
                    }
                    onClick={() => void handleRecovery(account, 'reset_totp')}
                    type="button"
                  >
                    <AppIcon name="key" size={16} />
                    {recoveringUserId === account.id && recoveryAction === 'reset_totp'
                      ? 'Authenticating...'
                      : 'Reset TOTP'}
                  </button>
                  <button
                    className="admin-recovery-actions__button"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      recoveringUserId === account.id
                    }
                    onClick={() => void handleRecovery(account, 'reset_password')}
                    type="button"
                  >
                    <AppIcon name="keyReset" size={16} />
                    {recoveringUserId === account.id && recoveryAction === 'reset_password'
                      ? 'Authenticating...'
                      : 'Reset password'}
                  </button>
                  <button
                    className="admin-recovery-actions__button admin-recovery-actions__button--destructive"
                    disabled={
                      account.role === 'admin' ||
                      account.id === user.id ||
                      recoveringUserId === account.id ||
                      account.enrollment.passkeyCount === 0
                    }
                    onClick={() => void handleRecovery(account, 'revoke_passkeys')}
                    type="button"
                  >
                    <AppIcon name="delete" size={16} />
                    {recoveringUserId === account.id && recoveryAction === 'revoke_passkeys'
                      ? 'Authenticating...'
                      : 'Revoke keys'}
                  </button>
                  {account.status === 'active' ? (
                    <button
                      className="admin-recovery-actions__button admin-recovery-actions__button--suspend"
                      disabled={
                        account.role === 'admin' ||
                        account.id === user.id ||
                        updatingStatusUserId === account.id
                      }
                      onClick={() => void handleStatusUpdate(account, 'suspend')}
                      type="button"
                    >
                      <AppIcon name="suspend" size={16} />
                      {updatingStatusUserId === account.id && statusAction === 'suspend'
                        ? 'Authenticating...'
                        : 'Suspend'}
                    </button>
                  ) : (
                    <button
                      className="admin-recovery-actions__button"
                      disabled={
                        account.role === 'admin' ||
                        account.id === user.id ||
                        updatingStatusUserId === account.id
                      }
                      onClick={() => void handleStatusUpdate(account, 'reactivate')}
                      type="button"
                    >
                      <AppIcon name="verify" size={16} />
                      {updatingStatusUserId === account.id && statusAction === 'reactivate'
                        ? 'Authenticating...'
                        : 'Reactivate'}
                    </button>
                  )}
                </div>
              </div>

              {account.status !== 'active' && account.suspensionReason ? (
                <p className="admin-role-assignment__note">
                  Suspension reason: {account.suspensionReason}
                </p>
              ) : null}

              {account.role === 'admin' ? (
                <p className="admin-role-assignment__note">
                  Admin account role, recovery, and status changes are locked for the later hardened admin-account flow.
                </p>
              ) : null}
            </article>
          ))}
            </div>
          </section>
        </>
      ) : null}
    </section>
  )
}
