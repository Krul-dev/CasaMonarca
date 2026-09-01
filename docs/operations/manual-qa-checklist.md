# Manual QA Checklist

These checks require browser, passkey, or staging-server access and should be
completed manually before marking the current security/document workflow as
reviewed.

## Verification Package QA

Status: pending after verifier redesign. Firefox blocks automatic sibling-file
reads from `file://`, so `verify.html` now embeds the verification evidence and
only asks the user to drop or select the confidential revision file.

- Deploy the latest API and Web branches to staging.
- Download a verification package from a signed document revision.
- Extract the package locally.
- Open `verify.html` from the extracted folder.
- Drop or select the bundled confidential revision file.
- Confirm verification succeeds without selecting `verification.json`.

## Staging Deployment Smoke Test

Status: pending.

- Deploy the latest API and Web code to staging.
- Confirm the public domain reaches the React app through the reverse proxy.
- Confirm `/api/health` returns `200`.
- Confirm passkey login works over the public HTTPS origin.
- Confirm audit logs show the client IP after the reverse-proxy headers are
  trusted by Laravel.

## Passkey-Gated Admin Controls

Status: pending.

- Verify role changes require an admin passkey assertion.
- Verify TOTP reset requires an admin passkey assertion.
- Verify passkey revocation requires an admin passkey assertion.
- Verify suspend/reactivate requires an admin passkey assertion.
- Verify coordinator promotion is blocked until the target user has a passkey.
