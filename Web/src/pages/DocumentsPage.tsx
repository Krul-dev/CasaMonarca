import { type ChangeEvent, useEffect, useMemo, useRef, useState } from 'react'

import { AppIcon } from '../components/ui/AppIcon'
import { DocumentSignaturePolicyPanel } from '../components/documents/DocumentSignaturePolicyPanel'
import type { AuthenticatedUser } from '../lib/auth'
import { ApiRequestError } from '../lib/api'
import { cancelSecurityChallenge } from '../lib/securityChallenges'
import {
  downloadDocumentBinary,
  downloadDocumentRevisionBinary,
  getDocument,
  getDocumentDownloadUrl,
  getDocumentRevisionDownloadUrl,
  getDocumentRevisionVerificationBundle,
  getDocumentRevisionVerificationPackageUrl,
  getDocumentVerificationPackageUrl,
  getDocuments,
  getDocumentVerification,
  getDocumentVerificationBundle,
  startDocumentRevisionUpdate,
  startDocumentDelete,
  startDocumentRevisionSign,
  verifyDocumentRevisionUpdate,
  verifyDocumentDelete,
  verifyDocumentRevisionSign,
  type DocumentDetail,
  type DocumentDetailRevision,
  type DocumentSummary,
  type DocumentVerification,
} from '../lib/documents'
import {
  verifyDocumentBundleLocally,
  type LocalDocumentVerificationReport,
} from '../lib/localDocumentVerification'
import { getSignatureValidityState } from '../lib/signatureValidity'
import { getWebauthnAssertion, isIpHostname } from '../lib/webauthn'

type DocumentsPageProps = {
  locationSearch?: string
  onSessionExpired?: () => void
  user: AuthenticatedUser
}

type SensitiveAction = 'idle' | 'deleting' | 'updating'

type ActionFeedback =
  | {
      kind: 'success'
      message: string
    }
  | {
      kind: 'error'
      message: string
    }

const bytesToHex = (value: Uint8Array) =>
  Array.from(value)
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('')

const hashFileSha256 = async (file: File) => {
  const digest = await crypto.subtle.digest('SHA-256', await file.arrayBuffer())

  return bytesToHex(new Uint8Array(digest))
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

const formatBytes = (value?: number | null) => {
  if (typeof value !== 'number' || Number.isNaN(value)) {
    return 'Not available'
  }

  if (value < 1024) {
    return `${value} B`
  }

  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`
  }

  return `${(value / (1024 * 1024)).toFixed(2)} MB`
}

const formatAlgorithm = (value?: number | null) => {
  switch (value) {
    case -7:
      return 'ES256'
    case -257:
      return 'RS256'
    case null:
    case undefined:
      return 'Not available'
    default:
      return `COSE ${value}`
  }
}

const formatDiffKind = (revision: DocumentDetailRevision) => {
  const kind = revision.diffMetadata?.kind

  if (typeof kind !== 'string' || kind.trim() === '') {
    return revision.parentRevisionId == null ? 'Initial revision' : 'Revision update'
  }

  return kind
    .split('_')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ')
}

const formatParentRevision = (revision: DocumentDetailRevision) =>
  revision.parentRevisionId == null
    ? 'Root revision'
    : `Parent revision ID ${revision.parentRevisionId}`

const toDownloadSafeSegment = (value: string) => {
  const normalized = value
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')

  return normalized || 'document'
}

const downloadJsonFile = (filename: string, payload: unknown) => {
  const blob = new Blob([JSON.stringify(payload, null, 2)], {
    type: 'application/json',
  })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = filename
  anchor.click()

  URL.revokeObjectURL(url)
}

const omitRevisionEntry = <T,>(entries: Record<number, T>, revisionId: number) => {
  const nextEntries = { ...entries }

  delete nextEntries[revisionId]

  return nextEntries
}

const parsePositiveIntegerParam = (search: string | undefined, key: string) => {
  const value = new URLSearchParams(search ?? '').get(key)
  const numericValue = value ? Number(value) : NaN

  return Number.isInteger(numericValue) && numericValue > 0 ? numericValue : null
}

export function DocumentsPage({ locationSearch, onSessionExpired, user }: DocumentsPageProps) {
  const revisionFileInputRef = useRef<HTMLInputElement | null>(null)
  const requestedDocumentId = useMemo(
    () => parsePositiveIntegerParam(locationSearch, 'documentId'),
    [locationSearch],
  )
  const requestedRevisionId = useMemo(
    () => parsePositiveIntegerParam(locationSearch, 'revisionId'),
    [locationSearch],
  )
  const [documents, setDocuments] = useState<DocumentSummary[]>([])
  const [isLoadingList, setIsLoadingList] = useState(true)
  const [listError, setListError] = useState<string | null>(null)
  const [selectedDocumentId, setSelectedDocumentId] = useState<number | null>(requestedDocumentId)
  const [selectedRevisionId, setSelectedRevisionId] = useState<number | null>(requestedRevisionId)
  const [detail, setDetail] = useState<DocumentDetail | null>(null)
  const [verification, setVerification] = useState<DocumentVerification | null>(
    null,
  )
  const [isLoadingDetail, setIsLoadingDetail] = useState(false)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [reloadToken, setReloadToken] = useState(0)
  const [pendingAction, setPendingAction] = useState<SensitiveAction>('idle')
  const [actionFeedback, setActionFeedback] = useState<ActionFeedback | null>(
    null,
  )
  const [isDownloadingVerificationBundle, setIsDownloadingVerificationBundle] =
    useState(false)
  const [downloadingBundleRevisionId, setDownloadingBundleRevisionId] = useState<
    number | null
  >(null)
  const [isVerifyingLocally, setIsVerifyingLocally] = useState(false)
  const [verifyingRevisionId, setVerifyingRevisionId] = useState<number | null>(
    null,
  )
  const [localVerificationError, setLocalVerificationError] = useState<string | null>(
    null,
  )
  const [localVerificationErrors, setLocalVerificationErrors] = useState<
    Record<number, string>
  >({})
  const [localVerificationReport, setLocalVerificationReport] =
    useState<LocalDocumentVerificationReport | null>(null)
  const [localVerificationReports, setLocalVerificationReports] = useState<
    Record<number, LocalDocumentVerificationReport>
  >({})
  const [pendingSigningRevisionId, setPendingSigningRevisionId] = useState<
    number | null
  >(null)
  const [signatureClockMs, setSignatureClockMs] = useState(() => Date.now())

  const resetSelectedDocumentState = () => {
    setSelectedDocumentId(null)
    setSelectedRevisionId(null)
    setDetail(null)
    setVerification(null)
    setDetailError(null)
    setIsLoadingDetail(false)
  }

  const selectDocument = (documentId: number | null) => {
    if (documentId === selectedDocumentId) {
      return
    }

    setActionFeedback(null)
    setSelectedDocumentId(documentId)
    setDetailError(null)

    if (documentId == null) {
      setSelectedRevisionId(null)
      setDetail(null)
      setVerification(null)
      setIsLoadingDetail(false)
      return
    }

    setIsLoadingDetail(true)
    setSelectedRevisionId(null)
    setDetail(null)
    setVerification(null)
  }

  const refreshDocuments = () => {
    setIsLoadingList(true)
    setListError(null)
    setReloadToken((current) => current + 1)
  }

  useEffect(() => {
    if (!requestedDocumentId) {
      return
    }

    setActionFeedback(null)
    setSelectedDocumentId(requestedDocumentId)
    setSelectedRevisionId(requestedRevisionId)
    setDetailError(null)
    setIsLoadingDetail(true)
    setDetail(null)
    setVerification(null)
  }, [requestedDocumentId, requestedRevisionId])

  useEffect(() => {
    let isMounted = true

    getDocuments()
      .then((response) => {
        if (!isMounted) {
          return
        }

        setDocuments(response.documents)
        setIsLoadingList(false)

        if (response.documents.length === 0) {
          resetSelectedDocumentState()
          return
        }

        setSelectedDocumentId((current) => {
          const requestedSelectionExists = response.documents.some(
            (document) => document.id === requestedDocumentId,
          )
          const selectionStillExists = response.documents.some(
            (document) => document.id === current,
          )
          let nextDocumentId = response.documents[0].id

          if (requestedSelectionExists && requestedDocumentId !== null) {
            nextDocumentId = requestedDocumentId
          } else if (selectionStillExists && current !== null) {
            nextDocumentId = current
          }

          if (nextDocumentId !== current) {
            setDetailError(null)
            setIsLoadingDetail(true)
            setDetail(null)
            setVerification(null)
          }

          return nextDocumentId
        })
      })
      .catch((error) => {
        if (!isMounted) {
          return
        }

        if (error instanceof ApiRequestError && error.status === 401) {
          onSessionExpired?.()
          return
        }

        setListError(
          error instanceof Error ? error.message : 'Failed to load documents.',
        )
        setIsLoadingList(false)
      })

    return () => {
      isMounted = false
    }
  }, [onSessionExpired, reloadToken, requestedDocumentId])

  useEffect(() => {
    if (selectedDocumentId == null) {
      return
    }

    let isMounted = true

    Promise.all([
      getDocument(selectedDocumentId),
      getDocumentVerification(selectedDocumentId),
    ])
      .then(([documentResponse, verificationResponse]) => {
        if (!isMounted) {
          return
        }

        setIsLoadingDetail(false)
        setDetail(documentResponse.document)
        setVerification(verificationResponse.verification)
      })
      .catch((error) => {
        if (!isMounted) {
          return
        }

        if (error instanceof ApiRequestError && error.status === 401) {
          onSessionExpired?.()
          return
        }

        setDetail(null)
        setVerification(null)
        setDetailError(
          error instanceof Error
            ? error.message
            : 'Failed to load the selected document.',
        )
        setIsLoadingDetail(false)
      })

    return () => {
      isMounted = false
    }
  }, [onSessionExpired, selectedDocumentId])

  useEffect(() => {
    const intervalId = window.setInterval(() => {
      setSignatureClockMs(Date.now())
    }, 1000)

    return () => {
      window.clearInterval(intervalId)
    }
  }, [])

  const currentUserAlreadySigned =
    verification?.signatures.some((signature) => signature.signedBy.id === user.id) ??
    false
  const documentCapabilities = detail?.capabilities ?? null
  const canPerformPrivilegedDocumentActions =
    user.role === 'admin' || user.role === 'coordinator'
  const canDeleteSelectedDocument =
    user.role === 'admin' && documentCapabilities?.canDeleteDocument === true
  const canUploadSelectedRevision =
    canPerformPrivilegedDocumentActions &&
    documentCapabilities?.canUploadRevision === true
  const canUseVersioning = user.capabilities.modules.history
  const sortedRevisions = useMemo(() => detail?.revisions ?? [], [detail?.revisions])
  const selectedRevision = useMemo(() => {
    if (!canUseVersioning || sortedRevisions.length === 0) {
      return null
    }

    return sortedRevisions.find((revision) => revision.id === selectedRevisionId) ??
      sortedRevisions.find((revision) => revision.id === detail?.currentRevision?.id) ??
      sortedRevisions[0]
  }, [canUseVersioning, detail?.currentRevision?.id, selectedRevisionId, sortedRevisions])
  const selectedRevisionHasStoredSignatures =
    (selectedRevision?.signatures?.length ?? 0) > 0
  const selectedRevisionSignedByCurrentUser =
    selectedRevision?.signatures?.some(
      (signature) => signature.signedBy.id === user.id,
    ) ?? false
  const isSelectedRevisionCurrent =
    selectedRevision?.id === detail?.currentRevision?.id
  const selectedRevisionSigningBlock = useMemo(() => {
    if (!detail || !selectedRevision || selectedRevision.capabilities.canSign) {
      return null
    }

    return 'Signing unavailable for this revision'
  }, [detail, selectedRevision])
  const isSigningSelectedRevision =
    selectedRevision ? pendingSigningRevisionId === selectedRevision.id : false
  const isDownloadingSelectedRevisionBundle =
    selectedRevision ? downloadingBundleRevisionId === selectedRevision.id : false
  const isVerifyingSelectedRevision =
    selectedRevision ? verifyingRevisionId === selectedRevision.id : false
  const selectedRevisionLocalVerificationError = selectedRevision
    ? localVerificationErrors[selectedRevision.id] ?? null
    : null
  const selectedRevisionLocalVerificationReport = selectedRevision
    ? localVerificationReports[selectedRevision.id] ?? null
    : null

  useEffect(() => {
    if (!canUseVersioning || !detail || sortedRevisions.length === 0) {
      setSelectedRevisionId(null)
      return
    }

    setSelectedRevisionId((current) => {
      const requestedRevisionExists = sortedRevisions.some(
        (revision) => revision.id === requestedRevisionId,
      )
      const currentStillExists = sortedRevisions.some(
        (revision) => revision.id === current,
      )

      if (requestedRevisionExists && requestedRevisionId !== null) {
        return requestedRevisionId
      }

      if (currentStillExists) {
        return current
      }

      return detail.currentRevision?.id ?? sortedRevisions[0].id
    })
  }, [canUseVersioning, detail, requestedRevisionId, sortedRevisions])

  const handleUpdateDocumentRevision = async (file: File) => {
    if (
      !detail ||
      !detail.currentRevision ||
      !canUploadSelectedRevision
    ) {
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setActionFeedback({
        kind: 'error',
        message:
          'Las actualizaciones de revisión requieren localhost o un nombre de dominio. Abre esta app desde localhost o tu dominio de staging.',
      })
      return
    }

    setPendingAction('updating')
    setActionFeedback(null)

    try {
      const sha256 = await hashFileSha256(file)
      const optionsResponse = await startDocumentRevisionUpdate(detail.id, {
        originalFileName: file.name,
        sha256,
        sizeBytes: file.size,
      })
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const updateResponse = await verifyDocumentRevisionUpdate(detail.id, {
        assertion,
        file,
      })
      const [documentResponse, verificationResponse] = await Promise.all([
        getDocument(detail.id),
        getDocumentVerification(detail.id),
      ])

      setDetail(documentResponse.document)
      setSelectedRevisionId(documentResponse.document.currentRevision?.id ?? null)
      setVerification(verificationResponse.verification)
      setLocalVerificationError(null)
      setLocalVerificationReport(null)
      refreshDocuments()
      setActionFeedback({
        kind: 'success',
        message: updateResponse.message,
      })
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setActionFeedback({
        kind: 'error',
        message:
          error instanceof Error
            ? error.name === 'NotAllowedError'
              ? 'Se canceló la verificación con llave de seguridad.'
              : error.message
            : 'No se pudo cargar la revisión del documento.',
      })
    } finally {
      setPendingAction('idle')
    }
  }

  const handleRevisionFileSelected = (
    event: ChangeEvent<HTMLInputElement>,
  ) => {
    const file = event.currentTarget.files?.[0]
    event.currentTarget.value = ''

    if (file) {
      handleUpdateDocumentRevision(file)
    }
  }

  const reloadDocumentDetail = async () => {
    if (!detail) {
      return
    }

    const [documentResponse, verificationResponse] = await Promise.all([
      getDocument(detail.id),
      getDocumentVerification(detail.id),
    ])
    setDetail(documentResponse.document)
    setVerification(verificationResponse.verification)
    setDocuments((current) =>
      current.map((document) =>
        document.id === documentResponse.document.id
          ? {
              ...document,
              currentRevision: documentResponse.document.currentRevision,
              updatedAt: documentResponse.document.updatedAt,
            }
          : document,
      ),
    )
  }

  const handleSignRevision = async (revision: DocumentDetailRevision) => {
    if (!detail || !canPerformPrivilegedDocumentActions || !revision.capabilities.canSign) {
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setActionFeedback({
        kind: 'error',
        message:
          'La firma de revisiones requiere localhost o un nombre de dominio. Abre esta app desde localhost o tu dominio de staging.',
      })
      return
    }

    setPendingSigningRevisionId(revision.id)
    setActionFeedback(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startDocumentRevisionSign(detail.id, revision.id)
      challengeIntentId = optionsResponse.challengeIntent?.id ?? null
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const signResponse = await verifyDocumentRevisionSign(
        detail.id,
        revision.id,
        assertion,
      )
      const [documentResponse, verificationResponse] = await Promise.all([
        getDocument(detail.id),
        getDocumentVerification(detail.id),
      ])

      setDetail(documentResponse.document)
      setVerification(verificationResponse.verification)
      setLocalVerificationError(null)
      setLocalVerificationReport(null)
      setActionFeedback({
        kind: 'success',
        message: signResponse.message,
      })
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      if (
        error instanceof Error &&
        error.name === 'NotAllowedError' &&
        challengeIntentId
      ) {
        await cancelSecurityChallenge(challengeIntentId).catch(() => undefined)
      }

      setActionFeedback({
        kind: 'error',
        message:
          error instanceof Error
            ? error.name === 'NotAllowedError'
              ? 'Se canceló la verificación con llave de seguridad.'
              : error.message
            : 'No se pudo firmar la revisión.',
      })
    } finally {
      setPendingSigningRevisionId(null)
    }
  }

  const handleDeleteDocument = async () => {
    if (!detail || !canDeleteSelectedDocument) {
      return
    }

    if (
      !window.confirm(
        `¿Eliminar "${detail.title}" permanentemente? Esto quitará el contenido del documento y conservará solo el registro de auditoría de eliminación.`,
      )
    ) {
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setActionFeedback({
        kind: 'error',
        message:
          'La eliminación de documentos requiere localhost o un nombre de dominio. Abre esta app desde localhost o tu dominio de staging.',
      })
      return
    }

    setPendingAction('deleting')
    setActionFeedback(null)
    let challengeIntentId: string | null = null

    try {
      const optionsResponse = await startDocumentDelete(detail.id)
      challengeIntentId = optionsResponse.challengeIntent?.id ?? null
      const assertion = await getWebauthnAssertion(optionsResponse.options)
      const deleteResponse = await verifyDocumentDelete(detail.id, assertion)

      resetSelectedDocumentState()
      setLocalVerificationError(null)
      setLocalVerificationReport(null)
      setActionFeedback({
        kind: 'success',
        message: deleteResponse.message,
      })
      refreshDocuments()
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      if (
        error instanceof Error &&
        error.name === 'NotAllowedError' &&
        challengeIntentId
      ) {
        await cancelSecurityChallenge(challengeIntentId).catch(() => undefined)
      }

      setActionFeedback({
        kind: 'error',
        message:
          error instanceof Error
            ? error.name === 'NotAllowedError'
              ? 'Se canceló la verificación con llave de seguridad.'
              : error.message
            : 'No se pudo eliminar el documento.',
      })
    } finally {
      setPendingAction('idle')
    }
  }

  const handleVerifyLocally = async () => {
    if (
      !detail?.currentRevision ||
      !detail.capabilities.canReadCurrentVerificationBundle ||
      !verification?.signatures.length
    ) {
      return
    }

    setIsVerifyingLocally(true)
    setLocalVerificationError(null)
    setLocalVerificationReport(null)

    try {
      const [bundleResponse, fileBytes] = await Promise.all([
        getDocumentVerificationBundle(detail.id),
        downloadDocumentBinary(detail.id),
      ])

      const report = await verifyDocumentBundleLocally(
        bundleResponse.bundle,
        fileBytes,
      )

      setLocalVerificationReport(report)
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setLocalVerificationError(
        error instanceof Error
          ? error.message
          : 'No se pudo completar la verificación local.',
      )
    } finally {
      setIsVerifyingLocally(false)
    }
  }

  const handleDownloadVerificationBundle = async () => {
    if (
      !detail?.currentRevision ||
      !detail.title ||
      !detail.capabilities.canReadCurrentVerificationBundle
    ) {
      return
    }

    setIsDownloadingVerificationBundle(true)

    try {
      const bundleResponse = await getDocumentVerificationBundle(detail.id)
      const filename = `${toDownloadSafeSegment(detail.title)}-revision-${
        detail.currentRevision.revisionNumber ?? 'current'
      }-verification-bundle.json`

      downloadJsonFile(filename, bundleResponse.bundle)
      setActionFeedback({
        kind: 'success',
        message: 'Paquete de verificación descargado.',
      })
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setActionFeedback({
        kind: 'error',
        message:
          error instanceof Error
            ? error.message
            : 'No se pudo descargar el paquete de verificación.',
      })
    } finally {
      setIsDownloadingVerificationBundle(false)
    }
  }

  const handleDownloadRevisionVerificationBundle = async (
    revision: DocumentDetailRevision,
  ) => {
    if (!detail || !revision.capabilities.canReadVerificationBundle) {
      return
    }

    setDownloadingBundleRevisionId(revision.id)
    setActionFeedback(null)

    try {
      const bundleResponse = await getDocumentRevisionVerificationBundle(
        detail.id,
        revision.id,
      )
      const filename = `${toDownloadSafeSegment(detail.title)}-revision-${
        revision.revisionNumber
      }-verification-bundle.json`

      downloadJsonFile(filename, bundleResponse.bundle)
      setActionFeedback({
        kind: 'success',
        message: `Paquete de verificación descargado para la revisión ${revision.revisionNumber}.`,
      })
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setActionFeedback({
        kind: 'error',
        message:
          error instanceof Error
            ? error.message
            : 'No se pudo descargar el paquete de verificación de la revisión.',
      })
    } finally {
      setDownloadingBundleRevisionId(null)
    }
  }

  const handleVerifyRevisionLocally = async (revision: DocumentDetailRevision) => {
    if (
      !detail ||
      !revision.capabilities.canReadVerificationBundle ||
      !revision.signatures?.length
    ) {
      return
    }

    setVerifyingRevisionId(revision.id)
    setLocalVerificationErrors((current) =>
      omitRevisionEntry(current, revision.id),
    )
    setLocalVerificationReports((current) =>
      omitRevisionEntry(current, revision.id),
    )

    try {
      const [bundleResponse, fileBytes] = await Promise.all([
        getDocumentRevisionVerificationBundle(detail.id, revision.id),
        downloadDocumentRevisionBinary(detail.id, revision.id),
      ])

      const report = await verifyDocumentBundleLocally(
        bundleResponse.bundle,
        fileBytes,
      )

      setLocalVerificationReports((current) => ({
        ...current,
        [revision.id]: report,
      }))
    } catch (error) {
      if (error instanceof ApiRequestError && error.status === 401) {
        onSessionExpired?.()
        return
      }

      setLocalVerificationErrors((current) => ({
        ...current,
        [revision.id]:
          error instanceof Error
            ? error.message
            : 'No se pudo completar la verificación local para esta revisión.',
      }))
    } finally {
      setVerifyingRevisionId(null)
    }
  }

  useEffect(() => {
    setLocalVerificationError(null)
    setLocalVerificationReport(null)
    setLocalVerificationErrors({})
    setLocalVerificationReports({})
    setDownloadingBundleRevisionId(null)
    setVerifyingRevisionId(null)
    setIsVerifyingLocally(false)
  }, [selectedDocumentId, verification?.currentRevisionId, verification?.signatures.length])

  return (
    <section className="workspace-stack">
      <section className="workspace-panel workspace-panel--accent">
        <h2 className="workspace-panel__title">Espacio de documentos</h2>
        <p className="workspace-panel__copy">
          Revisa documentos, carga nuevas revisiones y firma revisiones específicas
          desde un solo lugar. La firma y la eliminación permanente requieren un reto
          nuevo con llave de acceso, no solo la sesión actual.
        </p>

        <div className="workspace-actions">
          <button
            className="workspace-action workspace-action--secondary"
            onClick={refreshDocuments}
            type="button"
          >
            <AppIcon name="refresh" />
            Actualizar documentos
          </button>
        </div>

        {actionFeedback ? (
          <div
            className={`login-feedback ${
              actionFeedback.kind === 'success'
                ? 'login-feedback--success'
                : 'login-feedback--error'
            }`}
          >
            {actionFeedback.message}
          </div>
        ) : null}
      </section>

      <section className="document-layout">
        <section className="workspace-panel workspace-panel--document-list">
          <h2 className="workspace-panel__title">Documentos disponibles</h2>

          {isLoadingList ? (
            <div className="route-status route-status--checking">
              Cargando lista de documentos...
            </div>
          ) : listError ? (
            <div className="login-feedback login-feedback--error">{listError}</div>
          ) : documents.length === 0 ? (
            <div className="document-empty">
              Todavía no hay documentos registrados. Usa el módulo de carga para crear el
              primer registro confidencial.
            </div>
          ) : (
            <div className="document-list" role="list">
              {documents.map((document) => {
                const isActive = document.id === selectedDocumentId

                return (
                  <button
                    key={document.id}
                    className={`document-list__item${
                      isActive ? ' document-list__item--active' : ''
                    }`}
                    onClick={() => selectDocument(document.id)}
                    type="button"
                  >
                    <span className="document-list__title">{document.title}</span>
                    <span className="document-list__meta">
                      Revisión{' '}
                      {document.currentRevision?.revisionNumber ?? 'No disponible'}
                    </span>
                    <span className="document-list__meta">
                      Cargado por: {document.uploadedBy.name ?? 'No disponible'}
                    </span>
                    <span className="document-list__meta">
                      Actualizado: {formatDateTime(document.updatedAt)}
                    </span>
                  </button>
                )
              })}
            </div>
          )}
        </section>

        <section className="workspace-stack">
          <section className="workspace-panel">
            <div className="document-detail-header">
              <div>
                <h2 className="workspace-panel__title">
                  {detail?.title ?? 'Documento seleccionado'}
                </h2>
                {selectedRevision ? (
                  <p className="workspace-panel__copy">
                    Viendo revisión {selectedRevision.revisionNumber}
                    {isSelectedRevisionCurrent ? ' · actual' : ''}
                  </p>
                ) : null}
              </div>

              {detail && canUseVersioning && sortedRevisions.length > 0 ? (
                <label className="revision-picker">
                  <span>Versión</span>
                  <select
                    onChange={(event) => {
                      const nextRevisionId = Number(event.currentTarget.value)

                      setSelectedRevisionId(Number.isNaN(nextRevisionId) ? null : nextRevisionId)
                    }}
                    value={selectedRevision?.id ?? ''}
                  >
                    {sortedRevisions.map((revision) => (
                      <option key={revision.id} value={revision.id}>
                        Revisión {revision.revisionNumber}
                        {revision.id === detail.currentRevision?.id ? ' (actual)' : ''}
                      </option>
                    ))}
                  </select>
                </label>
              ) : null}
            </div>

            {selectedDocumentId == null ? (
              <div className="document-empty">
                Selecciona un documento de la lista para revisar sus metadatos y
                estado de verificación.
              </div>
            ) : isLoadingDetail ? (
              <div className="route-status route-status--checking">
                Cargando detalles del documento...
              </div>
            ) : detailError ? (
              <div className="login-feedback login-feedback--error">
                {detailError}
              </div>
            ) : detail ? (
              <>
                <dl className="document-detail-grid">
                  <div className="document-detail-grid__item">
                    <dt>Estado</dt>
                    <dd>
                      <span className="document-badge">{detail.status}</span>
                    </dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>Cargado por</dt>
                    <dd>{detail.uploadedBy.name ?? 'No disponible'}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>Última actualización</dt>
                    <dd>{formatDateTime(detail.updatedAt)}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{selectedRevision ? 'Hash de revisión' : 'Hash actual'}</dt>
                    <dd className="document-detail-grid__value--mono">
                      {selectedRevision?.sha256 ?? detail.currentRevision?.sha256 ?? 'No disponible'}
                    </dd>
                  </div>
                </dl>

                <div className="workspace-actions">
                  {documentCapabilities?.canDownloadCurrent ? (
                    <a
                      className="workspace-action"
                      href={getDocumentDownloadUrl(detail.id)}
                      rel="noreferrer"
                      target="_blank"
                    >
                      <AppIcon name="download" />
                      Descargar archivo actual
                    </a>
                  ) : null}

                  {canUploadSelectedRevision ? (
                    <>
                      <input
                        ref={revisionFileInputRef}
                        className="visually-hidden"
                        onChange={handleRevisionFileSelected}
                        type="file"
                      />
                      <button
                        className="workspace-action workspace-action--secondary"
                        disabled={
                          pendingAction !== 'idle' ||
                          pendingSigningRevisionId !== null ||
                          !detail.currentRevision
                        }
                        onClick={() => revisionFileInputRef.current?.click()}
                        type="button"
                      >
                        <AppIcon name="upload" />
                        {pendingAction === 'updating'
                          ? 'Actualizando con llave de acceso...'
                          : 'Cargar nueva revisión'}
                      </button>
                    </>
                  ) : null}

                  {canDeleteSelectedDocument ? (
                    <button
                      className="workspace-action workspace-action--danger"
                      disabled={
                        pendingAction !== 'idle' || pendingSigningRevisionId !== null
                      }
                      onClick={handleDeleteDocument}
                      type="button"
                    >
                      <AppIcon name="delete" />
                      {pendingAction === 'deleting'
                        ? 'Eliminando permanentemente...'
                        : 'Eliminar permanentemente'}
                    </button>
                  ) : null}
                </div>

                {selectedRevision ? (
                  <article className="selected-revision-card">
                    <div className="revision-timeline__header">
                      <div>
                        <span className="document-badge">
                          Revisión {selectedRevision.revisionNumber}
                        </span>
                        <h3>{selectedRevision.originalFileName}</h3>
                      </div>
                      <span
                        className={`revision-timeline__status revision-timeline__status--${selectedRevision.signatureStatus}`}
                      >
                        {selectedRevision.signatureStatus}
                      </span>
                    </div>

                    <dl className="revision-facts">
                      <div className="revision-facts__item">
                        <dt>Padre</dt>
                        <dd>{formatParentRevision(selectedRevision)}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>Tipo de cambio</dt>
                        <dd>{formatDiffKind(selectedRevision)}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>Autoría</dt>
                        <dd>{selectedRevision.createdBy.name ?? 'No disponible'}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>Creada</dt>
                        <dd>{formatDateTime(selectedRevision.createdAt)}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>Tamaño</dt>
                        <dd>{formatBytes(selectedRevision.sizeBytes)}</dd>
                      </div>
                      <div className="revision-facts__item revision-facts__item--hash">
                        <dt>SHA-256</dt>
                        <dd>{selectedRevision.sha256}</dd>
                      </div>
                    </dl>

                    {selectedRevision.signatures?.length ? (
                      <p className="workspace-panel__copy">
                        Firmado por:{' '}
                        {selectedRevision.signatures
                          .map(
                            (signature) =>
                              signature.signedBy.name ?? 'Firmante desconocido',
                          )
                          .join(', ')}
                      </p>
                    ) : null}

                    <DocumentSignaturePolicyPanel
                      documentId={detail.id}
                      onReload={() => {
                        void reloadDocumentDetail()
                      }}
                      onSaved={reloadDocumentDetail}
                      onSessionExpired={onSessionExpired}
                      revision={selectedRevision}
                    />

                    <div className="workspace-actions">
                      {selectedRevision.capabilities.canDownload ? (
                        <a
                          className="workspace-action"
                          href={getDocumentRevisionDownloadUrl(
                            detail.id,
                            selectedRevision.id,
                          )}
                          rel="noreferrer"
                          target="_blank"
                        >
                          <AppIcon name="download" />
                          Descargar revisión {selectedRevision.revisionNumber}
                        </a>
                      ) : null}

                      {selectedRevisionHasStoredSignatures &&
                      selectedRevision.capabilities.canReadVerificationBundle ? (
                        <button
                          className="workspace-action workspace-action--secondary"
                          disabled={verifyingRevisionId !== null}
                          onClick={() => handleVerifyRevisionLocally(selectedRevision)}
                          type="button"
                        >
                          <AppIcon name="verify" />
                          {isVerifyingSelectedRevision
                            ? 'Verificando localmente...'
                            : `Verificar revisión ${selectedRevision.revisionNumber} localmente`}
                        </button>
                      ) : null}

                      {canPerformPrivilegedDocumentActions ? (
                        <button
                          className="workspace-action workspace-action--secondary"
                          disabled={
                            pendingAction !== 'idle' ||
                            pendingSigningRevisionId !== null ||
                            selectedRevisionSignedByCurrentUser ||
                            !selectedRevision.capabilities.canSign
                          }
                          onClick={() => handleSignRevision(selectedRevision)}
                          title={selectedRevisionSigningBlock ?? undefined}
                          type="button"
                        >
                          <AppIcon name="sign" />
                          {isSigningSelectedRevision
                              ? 'Firmando con llave de acceso...'
                              : selectedRevisionSignedByCurrentUser
                                ? 'Firmada por esta cuenta'
                              : selectedRevisionSigningBlock ?? `Sign revision ${selectedRevision.revisionNumber}`}
                        </button>
                      ) : null}
                    </div>

                    {selectedRevisionHasStoredSignatures &&
                    selectedRevision.capabilities.canReadVerificationBundle ? (
                      <details className="verification-export verification-export--compact">
                        <summary>
                          <AppIcon name="bundle" />
                          Evidencia de auditoría/exportación
                        </summary>
                        <p>
                          Descarga evidencia de verificación para auditoría o
                          validación externa. La revisión normal debe usar la
                          verificación local en esta app.
                        </p>
                        <div className="workspace-actions">
                          <a
                            className="workspace-action"
                            href={getDocumentRevisionVerificationPackageUrl(
                              detail.id,
                              selectedRevision.id,
                            )}
                            rel="noreferrer"
                            target="_blank"
                          >
                            <AppIcon name="download" />
                          Descargar paquete de verificación
                          </a>
                          <button
                            className="workspace-action workspace-action--secondary"
                            disabled={downloadingBundleRevisionId !== null}
                            onClick={() =>
                              handleDownloadRevisionVerificationBundle(
                                selectedRevision,
                              )
                            }
                            type="button"
                          >
                            <AppIcon name="bundle" />
                            {isDownloadingSelectedRevisionBundle
                              ? 'Descargando paquete...'
                              : `Descargar paquete de verificación de la revisión ${selectedRevision.revisionNumber}`}
                          </button>
                        </div>
                      </details>
                    ) : null}

                    {selectedRevisionLocalVerificationError ? (
                      <div className="login-feedback login-feedback--error">
                        {selectedRevisionLocalVerificationError}
                      </div>
                    ) : null}

                    {selectedRevisionLocalVerificationReport ? (
                      <div
                        className={`login-feedback ${
                          selectedRevisionLocalVerificationReport.verified
                            ? 'login-feedback--success'
                            : 'login-feedback--error'
                        }`}
                      >
                        {selectedRevisionLocalVerificationReport.verified
                          ? `La verificación local fue exitosa para la revisión ${selectedRevision.revisionNumber}.`
                          : `La verificación local terminó con una o más comprobaciones fallidas para la revisión ${selectedRevision.revisionNumber}.`}
                      </div>
                    ) : null}
                  </article>
                ) : canUseVersioning ? (
                  <p className="workspace-panel__copy">
                    La selección de revisiones queda disponible cuando este rol puede
                    revisar el historial del documento.
                  </p>
                ) : null}
              </>
            ) : null}
          </section>

          <section className="workspace-panel workspace-panel--accent">
            <h2 className="workspace-panel__title">Estado actual de verificación</h2>

            {verification ? (
              <>
                <dl className="document-detail-grid">
                  <div className="document-detail-grid__item">
                    <dt>Revisión</dt>
                    <dd>{verification.currentRevisionNumber ?? 'No disponible'}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>Estado de firma</dt>
                    <dd>
                      <span className="document-badge">
                        {verification.signatureStatus}
                      </span>
                    </dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>Tiene firmas</dt>
                    <dd>{verification.hasSignatures ? 'Sí' : 'No'}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>Verificado</dt>
                    <dd>{verification.verified ? 'Sí' : 'No'}</dd>
                  </div>
                </dl>

                {currentUserAlreadySigned ? (
                  <p className="workspace-panel__copy">
                    La sesión actual ya firmó esta revisión.
                  </p>
                ) : null}

                {verification.signatures.length > 0 ? (
                  <>
                    {documentCapabilities?.canReadCurrentVerificationBundle ? (
                      <>
                        <div className="workspace-actions">
                          <button
                            className="workspace-action workspace-action--secondary"
                            disabled={isVerifyingLocally}
                            onClick={handleVerifyLocally}
                            type="button"
                          >
                            <AppIcon name="verify" />
                            {isVerifyingLocally
                                ? 'Verificando localmente...'
                                : 'Verificar revisión actual localmente'}
                          </button>
                        </div>

                        <details className="verification-export">
                          <summary>
                            <AppIcon name="bundle" />
                            Evidencia de auditoría/exportación
                          </summary>
                          <p>
                            Descarga el paquete de verificación sin procesar para auditoría
                            o herramientas externas futuras. La revisión normal debe usar
                            la verificación local en la app.
                          </p>
                          <div className="workspace-actions">
                            <a
                              className="workspace-action"
                              href={getDocumentVerificationPackageUrl(
                                verification.documentId,
                              )}
                              rel="noreferrer"
                              target="_blank"
                            >
                              <AppIcon name="download" />
                              Descargar paquete de verificación
                            </a>
                            <button
                              className="workspace-action workspace-action--secondary"
                              disabled={isDownloadingVerificationBundle}
                              onClick={handleDownloadVerificationBundle}
                              type="button"
                            >
                              <AppIcon name="bundle" />
                              {isDownloadingVerificationBundle
                                  ? 'Descargando paquete...'
                                  : 'Descargar paquete de verificación'}
                            </button>
                          </div>
                        </details>
                      </>
                    ) : null}
                    {localVerificationError ? (
                      <div className="login-feedback login-feedback--error">
                        {localVerificationError}
                      </div>
                    ) : null}

                    {localVerificationReport ? (
                      <div
                        className={`login-feedback ${
                          localVerificationReport.verified
                            ? 'login-feedback--success'
                            : 'login-feedback--error'
                        }`}
                      >
                        <p>
                          {localVerificationReport.verified
                            ? `La verificación local fue exitosa para ${localVerificationReport.signatures.length} firma(s).`
                            : 'La verificación local terminó con una o más comprobaciones fallidas.'}
                        </p>
                      </div>
                    ) : null}

                    <ul className="revision-list">
                      {verification.signatures.map((signature) => {
                        const localVerificationResult =
                          localVerificationReport?.signatures.find(
                            (entry) => entry.signatureId === signature.id,
                          ) ?? null
                        const signatureValidity = getSignatureValidityState(
                          signature.expiresAt,
                          signatureClockMs,
                        )

                        const localChecks: Array<[string, boolean]> =
                          localVerificationResult
                            ? [
                                [
                                  'File hash',
                                  localVerificationResult.checks.documentHashMatches,
                                ],
                                [
                                  'Intent',
                                  localVerificationResult.checks.intentCanonical,
                                ],
                                [
                                  'Challenge',
                                  localVerificationResult.checks.challengeMatches,
                                ],
                                [
                                  'Origin',
                                  localVerificationResult.checks.clientDataOrigin,
                                ],
                                [
                                  'RP ID hash',
                                  localVerificationResult.checks.rpIdHash,
                                ],
                                [
                                  'Signature',
                                  localVerificationResult.checks.cryptographicSignature,
                                ],
                              ]
                            : []

                        return (
                          <li key={signature.id} className="revision-list__item">
                            <strong>{signature.signedBy.name ?? 'Firmante desconocido'}</strong>
                            <span>{signature.signatureType}</span>
                            <span>{signature.verificationStatus}</span>
                            <span>{formatDateTime(signature.signedAt)}</span>
                            <dl className="signature-receipt">
                              <div className="signature-receipt__item">
                                <dt>Hash del documento</dt>
                                <dd>{signature.documentHash ?? 'No disponible'}</dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>Huella de llave</dt>
                                <dd>
                                  {signature.credential.publicKeyFingerprintSha256 ??
                                    'No disponible'}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>ID de credencial</dt>
                                <dd>{signature.credential.id ?? 'No disponible'}</dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>Algoritmo</dt>
                                <dd>
                                  {formatAlgorithm(
                                    signature.credential.publicKeyAlgorithm,
                                  )}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>Conteo de firmas</dt>
                                <dd>
                                  {signature.credential.signCount ?? 'No disponible'}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>Vencimiento de política</dt>
                                <dd>
                                  {signatureValidity.expiresAt
                                    ? formatDateTime(signatureValidity.expiresAt)
                                    : 'No disponible'}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>Vence en</dt>
                                <dd
                                  className={
                                    signatureValidity.expired
                                      ? 'signature-receipt__countdown signature-receipt__countdown--expired'
                                      : 'signature-receipt__countdown'
                                  }
                                >
                                  {signatureValidity.countdownLabel}
                                </dd>
                              </div>
                            </dl>
                            {signature.credential.publicKey ? (
                              <details className="signature-receipt__details">
                                <summary>Mostrar llave pública codificada</summary>
                                <code className="signature-receipt__code">
                                  {signature.credential.publicKey}
                                </code>
                              </details>
                            ) : null}
                            {localVerificationResult ? (
                              <div
                                className={`local-verification-card ${
                                  localVerificationResult.verified
                                    ? 'local-verification-card--success'
                                    : 'local-verification-card--error'
                                }`}
                              >
                                <strong>
                                  {localVerificationResult.verified
                                    ? 'Verificación local aprobada'
                                    : 'Verificación local fallida'}
                                </strong>
                                <span>{localVerificationResult.message}</span>
                                <ul className="local-verification-checks">
                                  {localChecks.map(([label, passed]) => (
                                    <li
                                      key={label}
                                      className={
                                        passed
                                          ? 'local-verification-checks__item--pass'
                                          : 'local-verification-checks__item--fail'
                                      }
                                    >
                                      {label}: {passed ? 'correcto' : 'falló'}
                                    </li>
                                  ))}
                                </ul>
                              </div>
                            ) : null}
                          </li>
                        )
                      })}
                    </ul>
                  </>
                ) : (
                  <p className="workspace-panel__copy">
                    Todavía no hay firmas registradas para la revisión actual.
                  </p>
                )}
                <p className="workspace-panel__copy">
                  El recibo anterior muestra la huella de la llave de quien firma y el
                  hash del documento firmado. Usa la verificación local para validar
                  la revisión descargada contra la evidencia WebAuthn guardada en este navegador.
                </p>
              </>
            ) : (
              <p className="workspace-panel__copy">
                Los datos de verificación estarán disponibles al seleccionar un documento.
              </p>
            )}
          </section>

        </section>
      </section>
    </section>
  )
}
