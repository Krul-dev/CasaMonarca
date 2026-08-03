type Props = {
  status: string
}

export function MigrantStatusTimeline({ status }: Props) {
  return (
    <div className="status-timeline">
      <h3>{t('Current status', 'Estado actual')}</h3>
      <p>{status}</p>
    </div>
  )
}
import { translate as t } from '../../lib/i18n'
