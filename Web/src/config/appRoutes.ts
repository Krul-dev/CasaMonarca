import type {
  AuthenticatedUser,
  SessionModuleCapabilities,
  UserRole,
} from '../lib/auth'
import { arcoEnabled } from './env'

export const LOGIN_PATH = '/login'
export const REGISTER_PATH = '/register'
export const RESET_PASSWORD_PATH = '/reset-password'
export const APP_HOME_PATH = '/app'
export const APP_UPLOAD_PATH = '/app/upload'
export const APP_DOCUMENTS_PATH = '/app/documents'
export const APP_INVITES_PATH = '/app/invites'
export const APP_LOGGING_PATH = '/app/logging'
export const APP_ADMIN_PATH = '/app/admin'
export const APP_MIGRANT_REGISTRY_PATH = '/app/migrants/registry'
export const APP_MIGRANT_REGISTRATIONS_PATH = '/app/migrants/registrations'
export const APP_MIGRANT_APPROVALS_PATH = '/app/migrants/approvals'
export const APP_MIGRANT_ARCO_PATH = '/app/migrants/arco'
export const FORBIDDEN_PATH = '/403'

export type AppWorkspace = 'internal' | 'migrant'

export type AppRouteConfig = {
  copy: string
  kicker: string
  label: string
  path: string
  requiredModule: keyof SessionModuleCapabilities
  allowedRoles?: UserRole[]
  enabled?: boolean
  hidden?: boolean
  workspace: AppWorkspace
}

export const APP_ROUTE_CONFIG: AppRouteConfig[] = [
  {
    path: APP_HOME_PATH,
    label: 'Dashboard',
    kicker: 'Access overview',
    copy: 'Current session, credential state, and role-aware module access.',
    requiredModule: 'dashboard',
    workspace: 'internal',
  },
  {
    path: APP_UPLOAD_PATH,
    label: 'Document Upload',
    kicker: 'Submission intake',
    copy: 'Private document intake with confidential handling from the first upload.',
    requiredModule: 'upload',
    workspace: 'internal',
  },
  {
    path: APP_DOCUMENTS_PATH,
    label: 'Documents / VCS',
    kicker: 'View, sign, version',
    copy: 'Role-aware document review, revision signing, verification, and version history.',
    requiredModule: 'documents',
    workspace: 'internal',
  },
  {
    path: APP_INVITES_PATH,
    label: 'Invites',
    kicker: 'Account onboarding',
    copy: 'Role-bound invite lifecycle for coordinator, non coordinator, and volunteer account provisioning.',
    requiredModule: 'invites',
    workspace: 'internal',
  },
  {
    path: APP_LOGGING_PATH,
    label: 'Logging',
    kicker: 'Admin audit',
    copy: 'Restricted operational logs and future privileged audit views.',
    requiredModule: 'logging',
    workspace: 'internal',
  },
  {
    path: APP_ADMIN_PATH,
    label: 'Admin Panel',
    kicker: 'Admin only',
    copy: 'System administration and restricted configuration workflows.',
    requiredModule: 'admin',
    workspace: 'internal',
  },
  {
    path: APP_MIGRANT_REGISTRY_PATH,
    label: 'Migrant Registration',
    kicker: 'Migrant intake',
    copy: 'Structured migrant registration intake submitted into coordinator/admin approval.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator', 'volunteer'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_REGISTRATIONS_PATH,
    label: 'Current Registrations',
    kicker: 'Migrant directory',
    copy: 'Search, filter, and review current migrant registrations across the shared registry.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_APPROVALS_PATH,
    label: 'Review & Approval',
    kicker: 'Migrant validation',
    copy: 'Non-coordinator review followed by passkey-backed coordinator approval for migrant registrations.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_ARCO_PATH,
    label: 'ARCO Requests',
    kicker: 'Privacy rights',
    copy: 'Signed privacy-rights requests, review, resolution, and evidence for migrant records.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    enabled: arcoEnabled,
    workspace: 'migrant',
  },
]

export const APP_PATHS = APP_ROUTE_CONFIG.map((route) => route.path)

export const getRouteConfig = (pathname: string) =>
  APP_ROUTE_CONFIG.find((route) => route.path === pathname) ?? null

export const getRouteConfigForUser = (
  pathname: string,
  user: AuthenticatedUser,
) => {
  const route = getRouteConfig(pathname)

  if (!route || route.path !== APP_DOCUMENTS_PATH || user.capabilities.modules.history) {
    return route
  }

  return {
    ...route,
    label: 'Document Review',
    kicker: 'View, verify',
    copy: 'Role-aware access to review and verify available documents.',
  }
}

export const getRequiredModuleForPath = (pathname: string) =>
  getRouteConfig(pathname)?.requiredModule ?? null

export const isProtectedPath = (pathname: string) => APP_PATHS.includes(pathname)

export const canAccessRoute = (user: AuthenticatedUser, pathname: string) => {
  const route = getRouteConfig(pathname)
  const requiredModule = route?.requiredModule ?? null

  if (!route || !requiredModule || route.enabled === false) {
    return false
  }

  return (
    user.capabilities.modules[requiredModule] &&
    (!route.allowedRoles || route.allowedRoles.includes(user.role))
  )
}

export const getVisibleRoutesForUser = (user: AuthenticatedUser) =>
  APP_ROUTE_CONFIG
    .filter((route) => route.enabled !== false && !route.hidden && canAccessRoute(user, route.path))
    .map((route) => getRouteConfigForUser(route.path, user) ?? route)

export const getRoleLabel = (role: UserRole) => {
  switch (role) {
    case 'admin':
      return 'Admin'
    case 'coordinator':
      return 'Coordinator'
    case 'non_coordinator':
      return 'Non Coordinator'
    case 'volunteer':
      return 'Volunteer'
  }
}
