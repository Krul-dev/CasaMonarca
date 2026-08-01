import { useState } from 'react'

import { migrantDocumentsEnabled } from '../../config/env'
import type { AuthenticatedUser } from '../../lib/auth'
import { getArcoAccessDocumentUrl } from '../../lib/arco'
import type { ArcoDecision, ArcoRequest } from '../../types/arco'
import { MigrantDocumentsPanel } from '../registry/MigrantDocumentsPanel'
import { MigrantQuestionnaireViewer } from '../registry/MigrantQuestionnaireViewer'
import { AppIcon } from '../ui/AppIcon'

type Props = { busyId: number | null; onDecision: (request: ArcoRequest, stage: 'coordinator' | 'admin', decision: ArcoDecision) => void; onSessionExpired?: () => void; requests: ArcoRequest[]; user: AuthenticatedUser }
const label = (value: string) => {
  const labels: Record<string, string> = {
    access: 'Acceso',
    admin_approved: 'Aprobado por administración',
    cancellation: 'Cancelación',
    completed: 'Completada',
    coordinator_approved: 'Aprobado por coordinación',
    opposition: 'Oposición',
    pending_admin: 'Pendiente de administración',
    pending_coordinator: 'Pendiente de coordinación',
    rectification: 'Rectificación',
    reject: 'Rechazo',
    rejected: 'Rechazada',
  }

  return labels[value] ?? value.replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase())
}

export function ArcoRequestList({ busyId, onDecision, onSessionExpired, requests, user }: Props) {
  const [documentRequestIds, setDocumentRequestIds] = useState<Set<number>>(() => new Set())
  if (requests.length === 0) return <p className="workspace-panel__copy">No se han enviado solicitudes ARCO.</p>
  return <div className="arco-list">{requests.map((request) => {
    const canCoordinator = request.status === 'pending_coordinator' && (user.role === 'coordinator' || user.role === 'admin')
    const canAdmin = request.status === 'pending_admin' && user.role === 'admin'
    const before = request.original_payload_json ?? {}; const after = request.proposed_payload_json ?? {}
    const isQuestionnaire = before.schemaVersion === 2 || after.schemaVersion === 2
    const changed = request.request_type === 'rectification' && !isQuestionnaire ? Object.keys({ ...before, ...after }).filter((key) => JSON.stringify((before as Record<string, unknown>)[key]) !== JSON.stringify((after as Record<string, unknown>)[key])) : []
    return <article className="arco-item" key={request.id}>
      <div className="arco-item__header"><div><span className="arco-item__type">{label(request.request_type)}</span><h3>{String(request.registry_entry?.payload_json?.fullName || request.original_payload_json?.fullName || `Registro purgado #${request.registry_entry_id}`)}</h3><small>Solicitud #{request.id} · {new Date(request.created_at).toLocaleString()}</small></div><span className={`arco-status arco-status--${request.status}`}>{label(request.status)}</span></div>
      <p>{request.reason}</p>
      {changed.length > 0 ? <div className="arco-diff"><h4>Cambios propuestos</h4>{changed.map((key) => <div key={key}><strong>{label(key)}</strong><span>{String((before as Record<string, unknown>)[key] ?? 'No proporcionado')}</span><span>{String((after as Record<string, unknown>)[key] ?? 'No proporcionado')}</span></div>)}</div> : null}
      {isQuestionnaire ? <details className="arco-item__questionnaire"><summary>Ver datos del cuestionario</summary><h4>Información vigente</h4><MigrantQuestionnaireViewer payload={before} />{request.request_type === 'rectification' && after.schemaVersion === 2 ? <><h4>Rectificación propuesta</h4><MigrantQuestionnaireViewer payload={after} /></> : null}</details> : null}
      {request.signatures?.length ? <div className="arco-signatures"><strong>Cadena de firmas</strong>{request.signatures.map((signature) => <span key={signature.id}>{label(signature.action_type)} · {signature.actor?.email ?? signature.actor_role} · {new Date(signature.verified_at).toLocaleString()}</span>)}</div> : null}
      <div className="arco-item__actions">
        {(canCoordinator || canAdmin) ? <><button className="session-action session-action--inline" disabled={busyId === request.id} onClick={() => onDecision(request, canAdmin ? 'admin' : 'coordinator', 'approve')} type="button"><AppIcon name="verify" />Aprobar</button><button className="session-action session-action--quiet session-action--inline" disabled={busyId === request.id} onClick={() => onDecision(request, canAdmin ? 'admin' : 'coordinator', 'reject')} type="button">Rechazar</button></> : null}
        {request.request_type === 'access' && request.status === 'completed' && request.artifact && !request.artifact.purged_at ? <a className="session-action session-action--quiet session-action--inline" href={getArcoAccessDocumentUrl(request.id)}><AppIcon name="download" />Descargar paquete de Acceso</a> : null}
      </div>
      {migrantDocumentsEnabled && request.registry_entry ? (
        <details
          className="arco-item__documents"
          onToggle={(event) => {
            if (event.currentTarget.open) {
              setDocumentRequestIds((current) => new Set(current).add(request.id))
            }
          }}
        >
          <summary>Ver documentos cubiertos por esta solicitud</summary>
          {documentRequestIds.has(request.id) ? (
            <MigrantDocumentsPanel
              canDelete={false}
              canDownload={user.role === 'admin' || user.role === 'coordinator'}
              canDownloadArcoApproved={user.role === 'non_coordinator'}
              canView
              embedded
              entryId={request.registry_entry_id}
              onSessionExpired={onSessionExpired}
            />
          ) : null}
        </details>
      ) : request.request_type === 'cancellation' && request.status === 'completed' ? (
        <p className="arco-item__document-resolution">Los documentos adjuntos se purgaron permanentemente junto con el registro.</p>
      ) : null}
      {request.resolution_reason ? <p className="arco-item__resolution"><strong>Resolución:</strong> {request.resolution_reason}</p> : null}
    </article>
  })}</div>
}
