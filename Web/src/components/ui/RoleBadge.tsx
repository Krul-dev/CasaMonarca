import { getRoleLabel } from '../../config/appRoutes'
import type { UserRole } from '../../lib/auth'
import { getRoleToneClass } from './roleBadgeStyles'

type RoleBadgeProps = {
  className?: string
  role: UserRole
}

export function RoleBadge({ className = '', role }: RoleBadgeProps) {
  return (
    <span className={`${getRoleToneClass(role)}${className ? ` ${className}` : ''}`}>
      {getRoleLabel(role)}
    </span>
  )
}
