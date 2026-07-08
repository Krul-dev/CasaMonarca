import type { RegistrySignature } from '../../types/registry'

type Props = {
  signatures: RegistrySignature[]
}

export function SignatureEvidencePanel({ signatures }: Props) {
  if (signatures.length === 0) {
    return <p>No hay firmas registradas todavía.</p>
  }

  return (
    <div className="signature-panel">
      <h3>Evidencia de firmas</h3>
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
