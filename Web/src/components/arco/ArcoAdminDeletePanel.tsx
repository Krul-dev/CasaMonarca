type Props = {
  registryEntryId: number
}

export function ArcoAdminDeletePanel({ registryEntryId }: Props) {
  return (
    <div className="arco-admin-delete">
      <h3>{t('Administrative deletion', 'Eliminación administrativa')}</h3>
      <p>{t(`Registration #${registryEntryId}`, `Registro #${registryEntryId}`)}</p>
      <button type="button">{t('Delete permanently', 'Eliminar definitivamente')}</button>
    </div>
  )
}
import { translate as t } from '../../lib/i18n'
