import type { RegistrySignature } from '../../types/registry'
import { translate as t } from '../../lib/i18n'

type Props = {
  signatures: RegistrySignature[]
}

export function SignatureEvidencePanel({ signatures }: Props) {
  if (signatures.length === 0) {
    return <p>{t('No signatures have been recorded yet.', 'Aún no hay firmas registradas.')}</p>
  }

  return (
    <div className="signature-panel">
      <h3>{t('Signature evidence', 'Evidencia de firmas')}</h3>
      <ul>
        {signatures.map((signature) => (
          <li key={signature.id}>
            {signature.actor_role} · {signature.action_type} · {signature.algorithm}
          </li>
        ))}
      </ul>
    </div>
  )
}
