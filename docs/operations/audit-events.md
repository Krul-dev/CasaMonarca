# Audit Events

The API writes append-only rows into `audit_events` for privileged and document-sensitive actions.

## Implemented event names

- `auth.login.succeeded`
- `auth.login.failed`
- `auth.logout`
- `auth.totp.challenge.started`
- `auth.totp.challenge.failed`
- `auth.totp.enrollment.started`
- `auth.totp.enrollment.failed`
- `auth.totp.enrolled`
- `auth.passkey.login.challenge.started`
- `auth.passkey.login.succeeded`
- `auth.passkey.registration.challenge.started`
- `auth.passkey.registered`
- `auth.passkey.removed`
- `auth.authorization.denied`
- `document.created`
- `document.downloaded`
- `document.revision.challenge.started`
- `document.revision.created`
- `document.revision.downloaded`
- `document.delete.challenge.started`
- `document.signature.challenge.started`
- `document.verification_bundle.downloaded`
- `document.verification_package.downloaded`
- `document.signed`
- `document.deleted`
- `account.invite.created`
- `account.invite.creation.denied`
- `account.invite.verified`
- `account.invite.verification.denied`
- `account.invite.link.issued`
- `account.invite.link.issue.denied`
- `account.invite.redeemed`
- `account.invite.redemption.failed`
- `account.invite.revoked`
- `account.invite.revocation.denied`
- `admin.user.role_change.challenge.started`
- `admin.user.role_changed`
- `admin.user.recovery.challenge.started`
- `admin.user.totp_reset`
- `admin.user.passkeys_revoked`
- `admin.user.enabled`
- `admin.user.disabled`

## Stored columns

- `id`
- `occurred_at`
- `actor_user_id`
- `actor_role`
- `event_type`
- `resource_type`
- `resource_id`
- `document_id`
- `revision_id`
- `outcome`
- `request_id`
- `ip_address`
- `user_agent`
- `session_id_hash`
- `metadata`

## Current format rules

- searchable fields stay in first-class columns
- action-specific context goes into `metadata`
- document contents, passwords, TOTP codes, and raw secret material do not enter the audit log
- WebAuthn events only store non-secret summaries such as credential previews, counters, and policy metadata
