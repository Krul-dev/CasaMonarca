import { translate as t } from '../../lib/i18n'
type Props = {
  requestId: number
}

export function ArcoReviewPanel({ requestId }: Props) {
  return (
    <div className="arco-review">
      <h3>{t('ARCO review', 'Revisión ARCO')}</h3>
      <p>{t(`Request #${requestId}`, `Solicitud #${requestId}`)}</p>
      <button type="button">{t('Approve', 'Aprobar')}</button>
      <button type="button">{t('Reject', 'Rechazar')}</button>
      <button type="button">{t('Escalate to administration', 'Escalar a administración')}</button>
    </div>
  )
}
