type Props = {
  status: string
}

export function MigrantStatusTimeline({ status }: Props) {
  return (
    <div className="status-timeline">
      <h3>Estado actual</h3>
      <p>{status}</p>
    </div>
  )
}
