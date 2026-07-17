import type { ReactNode } from 'react'

import type { AuthenticatedUser, UserRole } from '../../lib/auth'

type RequireRoleProps = {
  children: ReactNode
  fallback: ReactNode
  requiredRoles: UserRole[]
  user: AuthenticatedUser | null
}

export function RequireRole({
  children,
  fallback,
  requiredRoles,
  user,
}: RequireRoleProps) {
  if (!user) {
    return <>{fallback}</>
  }

  return requiredRoles.includes(user.role) ? <>{children}</> : <>{fallback}</>
}
