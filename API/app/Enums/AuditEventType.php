<?php

namespace App\Enums;

enum AuditEventType: string
{
    case AuthLoginSucceeded = 'auth.login.succeeded';
    case AuthLoginFailed = 'auth.login.failed';
    case AuthLogout = 'auth.logout';
    case AuthSessionExpired = 'auth.session.expired';
    case AuthTotpChallengeStarted = 'auth.totp.challenge.started';
    case AuthTotpChallengeFailed = 'auth.totp.challenge.failed';
    case AuthTotpEnrollmentStarted = 'auth.totp.enrollment.started';
    case AuthTotpEnrollmentFailed = 'auth.totp.enrollment.failed';
    case AuthTotpEnrolled = 'auth.totp.enrolled';
    case AuthPasskeyLoginChallengeStarted = 'auth.passkey.login.challenge.started';
    case AuthPasskeyLoginSucceeded = 'auth.passkey.login.succeeded';
    case AuthDeviceRegistered = 'auth.device.registered';
    case AuthPasskeyRegistrationChallengeStarted = 'auth.passkey.registration.challenge.started';
    case AuthPasskeyRegistered = 'auth.passkey.registered';
    case AuthPasskeyRemoved = 'auth.passkey.removed';
    case AuthAuthorizationDenied = 'auth.authorization.denied';
    case SecurityChallengeCancelled = 'security.challenge.cancelled';
    case SecurityChallengeExpired = 'security.challenge.expired';
    case DocumentCreated = 'document.created';
    case DocumentApproved = 'document.approved';
    case DocumentApprovalRejected = 'document.approval.rejected';
    case DocumentDownloaded = 'document.downloaded';
    case DocumentRevisionChallengeStarted = 'document.revision.challenge.started';
    case DocumentRevisionCreated = 'document.revision.created';
    case DocumentRevisionDownloaded = 'document.revision.downloaded';
    case DocumentDeleteChallengeStarted = 'document.delete.challenge.started';
    case DocumentSignatureChallengeStarted = 'document.signature.challenge.started';
    case DocumentSigned = 'document.signed';
    case DocumentDeleted = 'document.deleted';
    case DocumentVerificationBundleDownloaded = 'document.verification_bundle.downloaded';
    case DocumentVerificationPackageDownloaded = 'document.verification_package.downloaded';
    case AccountInviteCreated = 'account.invite.created';
    case AccountInviteCreationDenied = 'account.invite.creation.denied';
    case AccountInviteVerified = 'account.invite.verified';
    case AccountInviteVerificationDenied = 'account.invite.verification.denied';
    case AccountInviteLinkIssued = 'account.invite.link.issued';
    case AccountInviteLinkIssueDenied = 'account.invite.link.issue.denied';
    case AccountInviteRedeemed = 'account.invite.redeemed';
    case AccountInviteRedemptionFailed = 'account.invite.redemption.failed';
    case AccountInviteRevoked = 'account.invite.revoked';
    case AccountInviteRevocationDenied = 'account.invite.revocation.denied';
    case AdminUserRoleChangeChallengeStarted = 'admin.user.role_change.challenge.started';
    case AdminUserRoleChanged = 'admin.user.role_changed';
    case AdminUserRecoveryChallengeStarted = 'admin.user.recovery.challenge.started';
    case AdminUserTotpReset = 'admin.user.totp_reset';
    case AdminUserPasskeysRevoked = 'admin.user.passkeys_revoked';
    case AdminUserPasswordResetIssued = 'admin.user.password_reset.issued';
    case AuthPasswordResetCompleted = 'auth.password_reset.completed';
    case AuthPasswordResetFailed = 'auth.password_reset.failed';
    case AdminUserStatusChangeChallengeStarted = 'admin.user.status_change.challenge.started';
    case AdminUserEnabled = 'admin.user.enabled';
    case AdminUserDisabled = 'admin.user.disabled';
    case AdminPolicySignatureValidityChanged = 'admin.policy.signature_validity_changed';
    case AdminPackageSigningKeyRotationChallengeStarted = 'admin.package_signing_key.rotation.challenge.started';
    case AdminPackageSigningKeyRotated = 'admin.package_signing_key.rotated';
    case VcsEntryCreated = 'vcs.entry.created';
    case VcsEntryAmended = 'vcs.entry.amended';
    case VcsEntryDeleted = 'vcs.entry.deleted';
}
