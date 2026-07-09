type Props = {
  registryEntryId: number
}

export function ArcoAdminDeletePanel({ registryEntryId }: Props) {
  return (
    <div className="arco-admin-delete">
      <h3>Eliminación administrativa</h3>
      <p>Registro #{registryEntryId}</p>
      <button type="button">Eliminar definitivamente</button>
    </div>
  )
}
