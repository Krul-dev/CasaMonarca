type Props = {
  requestId: number
}

export function ArcoReviewPanel({ requestId }: Props) {
  return (
    <div className="arco-review">
      <h3>Revisión ARCO</h3>
      <p>Solicitud #{requestId}</p>
      <button type="button">Aprobar</button>
      <button type="button">Rechazar</button>
      <button type="button">Escalar a admin</button>
    </div>
  )
}
