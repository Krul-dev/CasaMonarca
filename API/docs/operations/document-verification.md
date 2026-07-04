# Document Verification Strategy

This document captures the agreed direction for document-signature verification
and export surfaces.

## Current Decision

1. Keep in-app local verification.
   - Purpose: confirm that the current stored revision bytes match the stored
     WebAuthn signature evidence.
   - This remains useful because the browser can hash the downloaded revision
     and verify the stored signature bundle with Web Crypto.
   - This is a normal user-facing action for users who can view the document.

2. De-emphasize offline verification in the normal workflow.
   - WebAuthn/passkey signatures are detached from the file itself.
   - Honest offline verification requires additional evidence: the revision
     bytes, public key metadata, authenticator data, client data JSON,
     signature, and the canonical challenge intent.
   - Because of that, "offline verification" should not appear as a primary
     document action unless the UI exports all required evidence together.

3. Keep verification bundles as audit/export evidence.
   - The API can continue exposing verification bundles.
   - The UI should present bundle downloads as an advanced audit/export action,
     not as the main signing or verification path.
   - Bundle downloads are useful for external review, debugging, and future
     archival workflows.

4. Future replacement: export verification package.
   - Implemented package shape:
     - original revision file
     - `verification.json`
     - `manifest.json`
     - `manifest.signature.json`
     - `README.md`
     - standalone `verify.html` with embedded verification evidence
   - The user opens `verify.html` and drops/selects the confidential revision
     file. The page hashes that file and verifies it against the embedded
     WebAuthn evidence.
   - The standalone verifier also checks the server-signed manifest when the
     package signing key is configured. Unsigned development packages can still
     run the file/WebAuthn checks, but they should not be treated as fully
     package-authentic.
   - `verification.json` remains in the package for audit/export use, but the
     standalone verifier no longer requires selecting it manually.
   - The package is exposed as an audit/export action. It should not replace
     normal in-app verification for day-to-day document review.

## Boundary

The current implementation should not claim that a file can be independently
verified offline without the associated verification evidence. The signing
contract is WebAuthn-based, so the signed payload is the WebAuthn assertion over
the canonical challenge intent, which includes the revision hash.

The standalone `verify.html` is not a tamper-proof authority. If an attacker can
edit the local HTML, they can also edit the page output. The signed manifest
makes that tampering detectable when reviewers compare the package signing key
and manifest status against the expected Casa Monarca key. The verifier
therefore shows package fingerprints:

- evidence hash: SHA-256 of the embedded verification evidence
- verifier HTML hash: SHA-256 of the opened verifier page
- signed verifier template hash: SHA-256 of the server-side verifier template
  before evidence/manifest data is embedded
- manifest hash and manifest signature status
- expected document hash: SHA-256 of the signed revision

These fingerprints make tampering easier to detect, but they still depend on
the reviewer trusting the Casa Monarca package signing public key. Rotate and
publish that key separately from exported packages if these packages are used
for external review.

## Pending Hardening Tasks

- Add verification package signing key rotation controls.
- Rotation should be explicit, not automatic during package export.
- Rotation should record key id, public key fingerprint, creation time,
  retirement time, operator, and reason.
- Verification should accept packages signed by active keys and by retired keys
  during a defined validation window for already-exported packages.
- The admin surface should show the current package signing key fingerprint and
  require passkey-gated authorization before generating or retiring a key.
