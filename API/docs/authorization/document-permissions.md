# Document Authorization Matrix

This document captures the current agreed authorization rules for document and
revision workflows.

## Canonical Roles

- `admin`
- `coordinator`
- `non_coordinator`
- `volunteer`

## Document Actions

| Action | Admin | Coordinator | Non Coordinator | Volunteer |
| --- | --- | --- | --- | --- |
| View documents | Yes | Yes | Yes | No |
| Upload documents | Yes | Yes | Yes | Yes |
| Update current document revision | Yes | Yes | No | No |
| Sign current revision | Yes | Yes | No | No |
| Delete document permanently | Yes | No | No | No |
| Verify signatures on visible documents | Yes | Yes | Yes | No |
| View logging | Yes | No | No | No |
| Use admin panel | Yes | No | No | No |
| Create coordinator invite link | Yes | No | No | No |
| Create non coordinator invite link | Yes | Yes | No | No |
| Create volunteer invite link | Yes | Yes | No | No |

## Account Invitation and Onboarding Rules

The first implementation scope is coordinator, non coordinator, and volunteer onboarding through
pre-created invites. Admin self-service creation is deferred.

### Coordinator Invite Flow (Admin-created)

1. Invite model
   - Only admins can create coordinator invites.
   - Invite is bound to exactly one email and fixed role `coordinator`.
   - Token is single-use, high-entropy, and stored hashed in database.
   - Invite expires quickly (recommended: 24 hours, max 48 hours).
2. Out-of-band verification gate before sending
   - Invite stays in draft until identity verification is recorded.
   - Verification metadata must include `method`, `verified_by`, and
     `verified_at` (plus optional note).
   - Link generation remains blocked until verification is completed.
3. Redemption requirements
   - Redemption email must match invite email exactly.
   - Mandatory onboarding steps for coordinator activation:
     password setup, TOTP enrollment, and passkey enrollment.
4. Abuse protection
   - Rate limit redemption endpoints and cap failed attempts per token/IP.
   - Optionally enforce email-domain allowlist for coordinator invites.
5. Visibility and control
   - Admin can revoke any pending invite.
   - Expired invites are invalid automatically.
   - Lifecycle events are audited: created, verified, sent, opened, redeemed,
     failed, revoked, expired.

### Operational Invite Delegation

- Admins and coordinators can issue invites for roles `non_coordinator` and
  `volunteer`.
- Delegated invites are still role-bound and single-use.
- Non coordinator and volunteer onboarding requires email/password plus
  mandatory TOTP.
- Coordinators cannot issue invites for `coordinator` or `admin`.
- Post-login module access is blocked until required enrollment is complete:
  - `coordinator`: TOTP + passkey
  - `non_coordinator`: TOTP
  - `volunteer`: TOTP

## History / VCS Rules

### Admin

- Can read the full revision history for every document.
- Can sign any revision.
- Can perform future VCS write operations on any revision.

### Coordinator

- Full module access now requires coordinator security enrollment completion:
  TOTP enabled plus at least one registered passkey.
- Can read old revisions only when the revision is owned by that coordinator.
- Can sign old revisions only when the revision is owned by that coordinator.
- Can still sign the current revision through the main document workflow.
- Future VCS write operations should follow the same ownership rule.

### Non Coordinator

- Cannot read old revisions through the history / VCS surface.
- Cannot sign revisions.

### Volunteer

- Cannot view documents or revisions after upload.

## Ownership Rule

For the current implementation, revision ownership is determined by
`document_revisions.created_by_user_id`.

That means:

- a coordinator may read or sign an old revision only when
  `created_by_user_id === authenticated user id`
- current-revision signing for coordinators remains allowed even if they did not
  create that current revision

## Notes

- All visible documents remain confidential.
- Everyone who can view a document may verify signatures on the visible current
  revision.
- History / VCS read currently includes the revision list, revision-specific
  downloads, revision-specific verification bundles, and signing old revisions.
- Non coordinators may still download and verify the current revision through
  the main document endpoints, but they may not access old revisions through the
  history / VCS surface.
- Encryption at rest and richer audit/VCS write controls are future phases.
