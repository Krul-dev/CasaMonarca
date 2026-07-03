import type {
  AuthenticatedUser,
  SessionModuleCapabilities,
  UserRole,
} from '../lib/auth'

export const LOGIN_PATH = '/login'
export const REGISTER_PATH = '/register'
export const RESET_PASSWORD_PATH = '/reset-password'
export const APP_HOME_PATH = '/app'
export const APP_UPLOAD_PATH = '/app/upload'
export const APP_DOCUMENTS_PATH = '/app/documents'
export const APP_INVITES_PATH = '/app/invites'
export const APP_LOGGING_PATH = '/app/logging'
export const APP_ADMIN_PATH = '/app/admin'
export const FORBIDDEN_PATH = '/403'

export type AppRouteConfig = {
  copy: string
  kicker: string
  label: string
  path: string
  requiredModule: keyof SessionModuleCapabilities
}

export const APP_ROUTE_CONFIG: AppRouteConfig[] = [
  {
    path: APP_HOME_PATH,
    label: 'Dashboard',
    kicker: 'Access overview',
    copy: 'Current session, credential state, and role-aware module access.',
    requiredModule: 'dashboard',
  },
  {
    path: APP_UPLOAD_PATH,
    label: 'Document Upload',
    kicker: 'Submission intake',
    copy: 'Private document intake with confidential handling from the first upload.',
    requiredModule: 'upload',
  },
  {
    path: APP_DOCUMENTS_PATH,
    label: 'Documents / VCS',
    kicker: 'View, sign, version',
    copy: 'Role-aware document review, revision signing, verification, and version history.',
    requiredModule: 'documents',
  },
  {
    path: APP_INVITES_PATH,
    label: 'Invites',
    kicker: 'Account onboarding',
    copy: 'Role-bound invite lifecycle for coordinator, non coordinator, and volunteer account provisioning.',
    requiredModule: 'invites',
  },
  {
    path: APP_LOGGING_PATH,
    label: 'Logging',
    kicker: 'Admin audit',
    copy: 'Restricted operational logs and future privileged audit views.',
    requiredModule: 'logging',
  },
  {
    path: APP_ADMIN_PATH,
    label: 'Admin Panel',
    kicker: 'Admin only',
    copy: 'System administration and restricted configuration workflows.',
    requiredModule: 'admin',
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
  const requiredModule = getRequiredModuleForPath(pathname)

  if (!requiredModule) {
    return false
  }

  return user.capabilities.modules[requiredModule]
}

export const getVisibleRoutesForUser = (user: AuthenticatedUser) =>
  APP_ROUTE_CONFIG
    .filter((route) => user.capabilities.modules[route.requiredModule])
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
