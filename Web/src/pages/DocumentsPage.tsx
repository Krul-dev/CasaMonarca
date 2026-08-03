import { translate as t } from '../lib/i18n'
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
          t("Document revision updates require localhost or a domain name. Open this app from localhost or your staging domain.", "Las nuevas versiones de documentos requieren localhost o un nombre de dominio. Abre esta aplicación desde localhost o tu dominio de pruebas."),
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
              ? t("Security key verification was cancelled.", "Se canceló la verificación con llave de seguridad.")
              : error.message
            : t("The document revision could not be uploaded.", "No se pudo cargar la nueva versión del documento."),
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
          t("Revision signing requires localhost or a domain name. Open this app from localhost or your staging domain.", "La firma de versiones requiere localhost o un nombre de dominio. Abre esta aplicación desde localhost o tu dominio de pruebas."),
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
              ? t("Security key verification was cancelled.", "Se canceló la verificación con llave de seguridad.")
              : error.message
            : t("The revision could not be signed.", "No se pudo firmar la versión."),
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
        t(`Delete "${detail.title}" permanently? This will remove the document payload and keep only the tombstone audit record.`, `¿Eliminar "${detail.title}" permanentemente? Esto quitará el contenido del documento y conservará solo el registro de auditoría de eliminación.`),
      )
    ) {
      return
    }

    if (isIpHostname(window.location.hostname)) {
      setActionFeedback({
        kind: 'error',
        message:
          t("Document deletion requires localhost or a domain name. Open this app from localhost or your staging domain.", "La eliminación de documentos requiere localhost o un nombre de dominio. Abre esta app desde localhost o tu dominio de staging."),
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
              ? t("Security key verification was cancelled.", "Se canceló la verificación con llave de seguridad.")
              : error.message
            : t("The document could not be deleted.", "No se pudo eliminar el documento."),
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
          : t("Local verification could not be completed.", "No se pudo completar la verificación local."),
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
        message: t("Verification bundle downloaded.", "Paquete de verificación descargado."),
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
            : t("The verification bundle could not be downloaded.", "No se pudo descargar el paquete de verificación."),
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
        message: t(`Verification bundle downloaded for revision ${revision.revisionNumber}.`, `Paquete de verificación descargado para la versión ${revision.revisionNumber}.`),
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
            : t("The revision verification bundle could not be downloaded.", "No se pudo descargar el paquete de verificación de la versión."),
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
            : t("Local verification could not be completed for this revision.", "No se pudo completar la verificación local de esta versión."),
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
        <h2 className="workspace-panel__title">{t("Document workspace", "Espacio de documentos")}</h2>
        <p className="workspace-panel__copy">
          {t("Review documents, upload new revisions, and sign specific revisions from one place. Signing and permanent deletion require a fresh passkey challenge instead of relying only on the current session. ", "Consulta documentos, carga nuevas versiones y firma versiones específicas desde un solo lugar. La firma y la eliminación permanente requieren una nueva autenticación con llave de acceso. ")}</p>

        <div className="workspace-actions">
          <button
            className="workspace-action workspace-action--secondary"
            onClick={refreshDocuments}
            type="button"
          >
            <AppIcon name="refresh" />
            {t("Refresh documents ", "Actualizar documentos ")}</button>
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
          <h2 className="workspace-panel__title">{t("Available documents", "Documentos disponibles")}</h2>

          {isLoadingList ? (
            <div className="route-status route-status--checking">
              {t("Loading document list... ", "Cargando lista de documentos... ")}</div>
          ) : listError ? (
            <div className="login-feedback login-feedback--error">{listError}</div>
          ) : documents.length === 0 ? (
            <div className="document-empty">
              {t("No documents are registered yet. Use the upload module to create the first confidential record. ", "Todavía no hay documentos registrados. Usa el módulo de carga para crear el primer registro confidencial. ")}</div>
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
                      {t("Revision", "Versión")}{' '}
                      {document.currentRevision?.revisionNumber ?? t("Not available", "No disponible")}
                    </span>
                    <span className="document-list__meta">
                      {t("Uploaded by: ", "Cargado por: ")}{document.uploadedBy.name ?? t("Not available", "No disponible")}
                    </span>
                    <span className="document-list__meta">
                      {t("Updated: ", "Actualizado: ")}{formatDateTime(document.updatedAt)}
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
                  {detail?.title ?? t("Selected document", "Documento seleccionado")}
                </h2>
                {selectedRevision ? (
                  <p className="workspace-panel__copy">
                    {t("Viewing revision ", "Viendo la versión ")}{selectedRevision.revisionNumber}
                    {isSelectedRevisionCurrent ? t(" · current", " · actual") : ''}
                  </p>
                ) : null}
              </div>

              {detail && canUseVersioning && sortedRevisions.length > 0 ? (
                <label className="revision-picker">
                  <span>{t("Version", "Versión")}</span>
                  <select
                    onChange={(event) => {
                      const nextRevisionId = Number(event.currentTarget.value)

                      setSelectedRevisionId(Number.isNaN(nextRevisionId) ? null : nextRevisionId)
                    }}
                    value={selectedRevision?.id ?? ''}
                  >
                    {sortedRevisions.map((revision) => (
                      <option key={revision.id} value={revision.id}>
                        {t("Revision ", "Versión ")}{revision.revisionNumber}
                        {revision.id === detail.currentRevision?.id ? t(" (current)", " (actual)") : ''}
                      </option>
                    ))}
                  </select>
                </label>
              ) : null}
            </div>

            {selectedDocumentId == null ? (
              <div className="document-empty">
                {t("Select a document from the list to inspect its metadata and verification state. ", "Selecciona un documento de la lista para revisar sus metadatos y estado de verificación. ")}</div>
            ) : isLoadingDetail ? (
              <div className="route-status route-status--checking">
                {t("Loading document details... ", "Cargando detalles del documento... ")}</div>
            ) : detailError ? (
              <div className="login-feedback login-feedback--error">
                {detailError}
              </div>
            ) : detail ? (
              <>
                <dl className="document-detail-grid">
                  <div className="document-detail-grid__item">
                    <dt>{t("Status", "Estado")}</dt>
                    <dd>
                      <span className="document-badge">{detail.status}</span>
                    </dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{t("Uploaded by", "Cargado por")}</dt>
                    <dd>{detail.uploadedBy.name ?? t("Not available", "No disponible")}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{t("Last updated", "Última actualización")}</dt>
                    <dd>{formatDateTime(detail.updatedAt)}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{selectedRevision ? t("Revision hash", "Hash de la versión") : t("Current hash", "Hash actual")}</dt>
                    <dd className="document-detail-grid__value--mono">
                      {selectedRevision?.sha256 ?? detail.currentRevision?.sha256 ?? t("Not available", "No disponible")}
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
                      {t("Download current file ", "Descargar archivo actual ")}</a>
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
                          ? t("Updating with passkey...", "Actualizando con llave de acceso...")
                          : t("Upload new revision", "Cargar nueva versión")}
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
                        ? t("Deleting permanently...", "Eliminando permanentemente...")
                        : t("Permanently delete", "Eliminar permanentemente")}
                    </button>
                  ) : null}
                </div>

                {selectedRevision ? (
                  <article className="selected-revision-card">
                    <div className="revision-timeline__header">
                      <div>
                        <span className="document-badge">
                          {t("Revision ", "Versión ")}{selectedRevision.revisionNumber}
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
                        <dt>{t("Parent", "Padre")}</dt>
                        <dd>{formatParentRevision(selectedRevision)}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>{t("Change kind", "Tipo de cambio")}</dt>
                        <dd>{formatDiffKind(selectedRevision)}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>{t("Author", "Autoría")}</dt>
                        <dd>{selectedRevision.createdBy.name ?? t("Not available", "No disponible")}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>{t("Created", "Creada")}</dt>
                        <dd>{formatDateTime(selectedRevision.createdAt)}</dd>
                      </div>
                      <div className="revision-facts__item">
                        <dt>{t("Size", "Tamaño")}</dt>
                        <dd>{formatBytes(selectedRevision.sizeBytes)}</dd>
                      </div>
                      <div className="revision-facts__item revision-facts__item--hash">
                        <dt>SHA-256</dt>
                        <dd>{selectedRevision.sha256}</dd>
                      </div>
                    </dl>

                    {selectedRevision.signatures?.length ? (
                      <p className="workspace-panel__copy">
                        {t("Signed by:", "Firmado por:")}{' '}
                        {selectedRevision.signatures
                          .map(
                            (signature) =>
                              signature.signedBy.name ?? t("Unknown signer", "Firmante desconocido"),
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
                          {t("Download revision ", "Descargar versión ")}{selectedRevision.revisionNumber}
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
                            ? t("Verifying locally...", "Verificando localmente...")
                            : t(`Verify revision ${selectedRevision.revisionNumber} locally`, `Verificar localmente la versión ${selectedRevision.revisionNumber}`)}
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
                              ? t("Signing with passkey...", "Firmando con llave de acceso...")
                              : selectedRevisionSignedByCurrentUser
                                ? t("Signed by this account", "Firmada por esta cuenta")
                              : selectedRevisionSigningBlock ?? `Sign revision ${selectedRevision.revisionNumber}`}
                        </button>
                      ) : null}
                    </div>

                    {selectedRevisionHasStoredSignatures &&
                    selectedRevision.capabilities.canReadVerificationBundle ? (
                      <details className="verification-export verification-export--compact">
                        <summary>
                          <AppIcon name="bundle" />
                          {t("Audit/export evidence ", "Evidencia de auditoría/exportación ")}</summary>
                        <p>
                          {t("Download verification evidence for audit review or external validation. Normal review should use local verification in this app. ", "Descarga evidencia de verificación para auditoría o validación externa. La revisión normal debe usar la verificación local en esta app. ")}</p>
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
                          {t("Download verification package ", "Descargar paquete de verificación ")}</a>
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
                              ? t("Downloading bundle...", "Descargando paquete...")
                              : t(`Download revision ${selectedRevision.revisionNumber} verification bundle`, `Descargar el paquete de verificación de la versión ${selectedRevision.revisionNumber}`)}
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
                          ? t(`Local verification succeeded for revision ${selectedRevision.revisionNumber}.`, `La versión ${selectedRevision.revisionNumber} superó la verificación local.`)
                          : t(`Local verification completed with one or more failed checks for revision ${selectedRevision.revisionNumber}.`, `Una o más comprobaciones de la versión ${selectedRevision.revisionNumber} fallaron durante la verificación local.`)}
                      </div>
                    ) : null}
                  </article>
                ) : canUseVersioning ? (
                  <p className="workspace-panel__copy">
                    {t("Revision selection becomes available when this role can inspect document history. ", "La selección de versiones está disponible para los roles con acceso al historial del documento. ")}</p>
                ) : null}
              </>
            ) : null}
          </section>

          <section className="workspace-panel workspace-panel--accent">
            <h2 className="workspace-panel__title">{t("Current verification state", "Estado actual de verificación")}</h2>

            {verification ? (
              <>
                <dl className="document-detail-grid">
                  <div className="document-detail-grid__item">
                    <dt>{t("Revision", "Versión")}</dt>
                    <dd>{verification.currentRevisionNumber ?? t("Not available", "No disponible")}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{t("Signature status", "Estado de firma")}</dt>
                    <dd>
                      <span className="document-badge">
                        {verification.signatureStatus}
                      </span>
                    </dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{t("Has signatures", "Tiene firmas")}</dt>
                    <dd>{verification.hasSignatures ? t("Yes", "Sí") : 'No'}</dd>
                  </div>
                  <div className="document-detail-grid__item">
                    <dt>{t("Verified", "Verificado")}</dt>
                    <dd>{verification.verified ? t("Yes", "Sí") : 'No'}</dd>
                  </div>
                </dl>

                {currentUserAlreadySigned ? (
                  <p className="workspace-panel__copy">
                    {t("The current session has already signed this revision. ", "La sesión actual ya firmó esta versión. ")}</p>
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
                                ? t("Verifying locally...", "Verificando localmente...")
                                : t("Verify current revision locally", "Verificar localmente la versión actual")}
                          </button>
                        </div>

                        <details className="verification-export">
                          <summary>
                            <AppIcon name="bundle" />
                            {t("Audit/export evidence ", "Evidencia de auditoría/exportación ")}</summary>
                          <p>
                            {t("Download the raw verification bundle for audit review or future external tooling. Normal review should use local verification in the app. ", "Descarga el paquete de verificación sin procesar para auditoría o herramientas externas futuras. La revisión normal debe usar la verificación local en la app. ")}</p>
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
                              {t("Download verification package ", "Descargar paquete de verificación ")}</a>
                            <button
                              className="workspace-action workspace-action--secondary"
                              disabled={isDownloadingVerificationBundle}
                              onClick={handleDownloadVerificationBundle}
                              type="button"
                            >
                              <AppIcon name="bundle" />
                              {isDownloadingVerificationBundle
                                  ? t("Downloading bundle...", "Descargando paquete...")
                                  : t("Download verification bundle", "Descargar paquete de verificación")}
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
                            ? t(`Local verification succeeded for ${localVerificationReport.signatures.length} signature(s).`, `La verificación local fue exitosa para ${localVerificationReport.signatures.length} firma(s).`)
                            : t("Local verification completed with one or more failed checks.", "La verificación local terminó con una o más comprobaciones fallidas.")}
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
                            <strong>{signature.signedBy.name ?? t("Unknown signer", "Firmante desconocido")}</strong>
                            <span>{signature.signatureType}</span>
                            <span>{signature.verificationStatus}</span>
                            <span>{formatDateTime(signature.signedAt)}</span>
                            <dl className="signature-receipt">
                              <div className="signature-receipt__item">
                                <dt>{t("Document hash", "Hash del documento")}</dt>
                                <dd>{signature.documentHash ?? t("Not available", "No disponible")}</dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>{t("Key fingerprint", "Huella de llave")}</dt>
                                <dd>
                                  {signature.credential.publicKeyFingerprintSha256 ??
                                    t("Not available", "No disponible")}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>{t("Credential ID", "ID de credencial")}</dt>
                                <dd>{signature.credential.id ?? t("Not available", "No disponible")}</dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>{t("Algorithm", "Algoritmo")}</dt>
                                <dd>
                                  {formatAlgorithm(
                                    signature.credential.publicKeyAlgorithm,
                                  )}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>{t("Sign count", "Conteo de firmas")}</dt>
                                <dd>
                                  {signature.credential.signCount ?? t("Not available", "No disponible")}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>{t("Policy expiry", "Vencimiento de política")}</dt>
                                <dd>
                                  {signatureValidity.expiresAt
                                    ? formatDateTime(signatureValidity.expiresAt)
                                    : t("Not available", "No disponible")}
                                </dd>
                              </div>
                              <div className="signature-receipt__item">
                                <dt>{t("Expires in", "Vence en")}</dt>
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
                                <summary>{t("Show encoded public key", "Mostrar llave pública codificada")}</summary>
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
                                    ? t("Local verification passed", "Verificación local aprobada")
                                    : t("Local verification failed", "Verificación local fallida")}
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
                                      {label}: {passed ? t("ok", "correcto") : t("failed", "falló")}
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
                    {t("No signatures are registered for the current revision yet. ", "Aún no hay firmas registradas para la versión actual. ")}</p>
                )}
                <p className="workspace-panel__copy">
                  {t("The receipt above exposes the signer key fingerprint and the signed document hash. Use the local verification action to validate the downloaded revision against the stored WebAuthn evidence in this browser. ", "El recibo muestra la huella de la llave de la persona firmante y el hash del documento. Usa la verificación local para validar la versión descargada con la evidencia WebAuthn almacenada en este navegador. ")}</p>
              </>
            ) : (
              <p className="workspace-panel__copy">
                {t("Verification data becomes available when a document is selected. ", "Los datos de verificación estarán disponibles al seleccionar un documento. ")}</p>
            )}
          </section>

        </section>
      </section>
    </section>
  )
}
