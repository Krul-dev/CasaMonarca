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
    label: 'Panel',
    kicker: 'Resumen de acceso',
    copy: 'Sesión actual, estado de credenciales y acceso a módulos según el rol.',
    requiredModule: 'dashboard',
    workspace: 'internal',
  },
  {
    path: APP_UPLOAD_PATH,
    label: 'Carga de documentos',
    kicker: 'Recepción de envíos',
    copy: 'Recepción privada de documentos con manejo confidencial desde la primera carga.',
    requiredModule: 'upload',
    workspace: 'internal',
  },
  {
    path: APP_DOCUMENTS_PATH,
    label: 'Documentos / VCS',
    kicker: 'Ver, firmar, versionar',
    copy: 'Revisión de documentos, firma de revisiones, verificación e historial de versiones según el rol.',
    requiredModule: 'documents',
    workspace: 'internal',
  },
  {
    path: APP_INVITES_PATH,
    label: 'Invitaciones',
    kicker: 'Alta de cuentas',
    copy: 'Ciclo de vida de invitaciones por rol para crear cuentas de coordinación, no coordinación y voluntariado.',
    requiredModule: 'invites',
    workspace: 'internal',
  },
  {
    path: APP_LOGGING_PATH,
    label: 'Registros',
    kicker: 'Auditoría administrativa',
    copy: 'Registros operativos restringidos y futuras vistas de auditoría privilegiada.',
    requiredModule: 'logging',
    workspace: 'internal',
  },
  {
    path: APP_ADMIN_PATH,
    label: 'Panel de administración',
    kicker: 'Solo administración',
    copy: 'Administración del sistema y flujos de configuración restringidos.',
    requiredModule: 'admin',
    workspace: 'internal',
  },
  {
    path: APP_MIGRANT_REGISTRY_PATH,
    label: 'Registro de migrantes',
    kicker: 'Recepción de migrantes',
    copy: 'Recepción estructurada de registros de migrantes enviados para revisión de coordinación y administración.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator', 'volunteer'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_REGISTRATIONS_PATH,
    label: 'Registros actuales',
    kicker: 'Directorio de migrantes',
    copy: 'Busca, filtra y revisa los registros actuales de migrantes en el registro compartido.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_APPROVALS_PATH,
    label: 'Revisión y aprobación',
    kicker: 'Validación de migrantes',
    copy: 'Revisión de no coordinación seguida por aprobación de coordinación respaldada con llave de acceso para registros de migrantes.',
    requiredModule: 'dashboard',
    allowedRoles: ['admin', 'coordinator', 'non_coordinator'],
    workspace: 'migrant',
  },
  {
    path: APP_MIGRANT_ARCO_PATH,
    label: 'Solicitudes ARCO',
    kicker: 'Derechos de privacidad',
    copy: 'Solicitudes firmadas de derechos de privacidad, revisión, resolución y evidencia para expedientes de migrantes.',
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
    label: 'Revisión de documentos',
    kicker: 'Ver, verificar',
    copy: 'Acceso según el rol para revisar y verificar documentos disponibles.',
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
      return 'Administrador'
    case 'coordinator':
      return 'Coordinador'
    case 'non_coordinator':
      return 'No coordinador'
    case 'volunteer':
      return 'Voluntario'
  }
}
