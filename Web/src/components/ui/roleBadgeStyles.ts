import type { UserRole } from '../../lib/auth'

export function getRoleToneClass(role: UserRole) {
  return `role-badge role-badge--${role}`
}
