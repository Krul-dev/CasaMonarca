import { describe, expect, it } from 'vitest'

import type { AuthenticatedUser } from '../lib/auth'
import {
  APP_HOME_PATH,
  APP_MIGRANT_REGISTRY_PATH,
  canAccessRoute,
  getVisibleRoutesForUser,
} from './appRoutes'

const buildUser = (isFullyEnrolled: boolean): AuthenticatedUser => ({
  id: 10,
  email: 'volunteer@example.test',
  name: 'Volunteer',
  role: 'volunteer',
  capabilities: {
    modules: {
      admin: false,
      dashboard: true,
      documents: false,
      history: false,
      invites: false,
      logging: false,
      upload: true,
    },
    security: {
      enrolled: { passkey: false, totp: isFullyEnrolled },
      enforced: true,
      isFullyEnrolled,
      missing: { passkey: false, totp: !isFullyEnrolled },
      requires: { passkey: false, totp: true },
    },
  },
})
describe('migrant workspace route access', () => {
  it('hides and denies migrant routes until security enrollment is complete', () => {
    const user = buildUser(false)

    expect(canAccessRoute(user, APP_HOME_PATH)).toBe(true)
    expect(canAccessRoute(user, APP_MIGRANT_REGISTRY_PATH)).toBe(false)
    expect(getVisibleRoutesForUser(user).every((route) => route.workspace !== 'migrant')).toBe(true)
  })

  it('restores role-appropriate migrant routes after enrollment', () => {
    const user = buildUser(true)

    expect(canAccessRoute(user, APP_MIGRANT_REGISTRY_PATH)).toBe(true)
    expect(getVisibleRoutesForUser(user).some((route) => route.path === APP_MIGRANT_REGISTRY_PATH)).toBe(true)
  })
})
