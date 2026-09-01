# User Administration Plan

This plan keeps account management in the admin module without deleting or
rewriting historical document, signature, or audit records.

## Phase 1: Account Directory

- List users with role, email, enrollment state, last sign-in, and account status.
- Use an admin-only endpoint: `GET /admin/users`.
- Expose TOTP/passkey enrollment state so admins can identify blocked accounts.
- Store `last_sign_in_at` after successful password, TOTP, or passkey login so
  admins can identify stale accounts.

## Phase 2: Role Assignment

- Allow admins to assign only operational roles: `coordinator`,
  `non_coordinator`, and `volunteer`.
- Do not allow self-service admin promotion in this phase.
- Do not change admin accounts in this phase; admin creation/demotion is
  deferred to a later hardened flow.
- Require a fresh admin passkey assertion for every role change, including
  changes between `volunteer` and `non_coordinator`.
- A user cannot be promoted to `coordinator` until they have registered at
  least one passkey. The API enforces this before challenge creation and again
  at verification time.
- A promoted coordinator must still complete any remaining coordinator
  enrollment requirements before protected module access.
- Require explicit confirmation and audit logging for every role change.
- Allow admins to include an optional audit reason when changing roles.
- Recompute required enrollment after role changes so elevated users cannot use
  protected modules before completing required TOTP/passkey setup.

## Phase 3: Enrollment Recovery Controls

- Allow admins to reset TOTP enrollment for users who lose authenticator access.
- Allow admins to revoke passkeys for users who lose a physical key.
- Require a fresh admin passkey assertion before either recovery action.
- Keep admin-account and self-recovery locked for a later hardened flow.
- Keep recovery operations auditable and avoid exposing TOTP secrets, passkey
  public keys, or raw credential material in logs.
- Allow admins to include an optional audit reason when resetting TOTP or
  revoking passkeys.
- Force affected users back through the required enrollment flow on next access.

## Phase 4: Account Status Controls

- Add suspend/reactivate controls instead of deleting users.
- Suspended accounts should not authenticate or access protected modules.
- Historical documents, signatures, invite records, and audit events remain
  linked to the original user id.
- Status changes require audit events and should include actor, target user,
  timestamp, and reason.
- Require a fresh admin passkey assertion before suspending or reactivating an
  account.
- Keep admin-account and self-status changes locked for the later hardened
  admin-account flow.
- Store `status`, `suspended_at`, `suspended_by_user_id`, and
  `suspension_reason` on the user record so account state is authoritative.
- Log status changes as `admin.user.disabled` and `admin.user.enabled`.

## Remaining Follow-Ups

- Harden the later admin-account flow for creating, demoting, recovering, or
  suspending admin accounts.
- Continue improving audit-log presentation for target-user actions.
