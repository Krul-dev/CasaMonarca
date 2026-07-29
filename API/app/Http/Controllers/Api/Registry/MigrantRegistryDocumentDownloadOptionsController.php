<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Http\Controllers\Controller;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use App\Services\Auth\Base64UrlService;
use App\Services\Auth\WebauthnAssertionService;
use App\Services\Registry\MigrantRegistryDocumentAccessService;
use App\Services\Security\SecurityChallengeIntentService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class MigrantRegistryDocumentDownloadOptionsController extends Controller
{
    public const INTENT_KEY = 'registry.migrants.document.download.webauthn.intent';

    public const CHALLENGE_INTENT_ID_KEY = 'registry.migrants.document.download.webauthn.challenge_intent_id';

    public function __construct(
        private readonly AuditEventService $auditEventService,
        private readonly Base64UrlService $base64UrlService,
        private readonly MigrantRegistryDocumentAccessService $documentAccessService,
        private readonly SecurityChallengeIntentService $securityChallengeIntentService,
        private readonly WebauthnAssertionService $webauthnAssertionService,
    ) {}

    public function __invoke(
        Request $request,
        MigrantRegistryEntry $migrantRegistryEntry,
        MigrantRegistryDocument $migrantRegistryDocument,
    ): JsonResponse {
        $this->authorizeDocument($migrantRegistryEntry, $migrantRegistryDocument);

        /** @var User|null $actor */
        $actor = $request->user();

        abort_unless(
            $actor instanceof User && $this->documentAccessService->canDownload($actor, $migrantRegistryDocument),
            403,
        );
        $this->assertDocumentAvailable($migrantRegistryDocument);

        $origin = $this->webauthnAssertionService->resolveRequestOrigin($request);
        $originHost = $this->webauthnAssertionService->resolveOriginHost($origin);

        if ($originHost === null || $this->webauthnAssertionService->isIpHost($originHost)) {
            return response()->json([
                'message' => 'Document download requires localhost or a domain name, not an IP address.',
            ], 422);
        }

        $credentials = $actor->webauthnCredentials()->get();

        if ($credentials->isEmpty()) {
            return response()->json([
                'message' => 'Register a security key before downloading migrant documents.',
            ], 422);
        }

        $issuedAt = CarbonImmutable::now('UTC');
        $expiresAt = $issuedAt->addMinute();
        $challenge = $this->base64UrlService->encode(random_bytes(32));
        $intent = [
            'version' => 1,
            'purpose' => 'migrant-document-download',
            'actorUserId' => (int) $actor->getKey(),
            'entryId' => (int) $migrantRegistryEntry->getKey(),
            'documentId' => (int) $migrantRegistryDocument->getKey(),
            'documentHash' => $this->documentHash($migrantRegistryDocument),
            'challenge' => $challenge,
            'origin' => $origin,
            'rpId' => $originHost,
            'issuedAt' => $issuedAt->toIso8601String(),
            'expiresAt' => $expiresAt->toIso8601String(),
        ];
        $challengeIntent = $this->securityChallengeIntentService->create(
            purpose: 'migrant.registry.document.download',
            challenge: $challenge,
            actor: $actor,
            origin: $origin,
            rpId: $originHost,
            expiresAt: $expiresAt,
            payload: [...$intent, 'challenge' => null, 'challengeRedacted' => true],
            targetType: 'migrant_registry_document',
            targetId: $migrantRegistryDocument->getKey(),
        );

        $request->session()->put([
            self::INTENT_KEY => $intent,
            self::CHALLENGE_INTENT_ID_KEY => $challengeIntent->getKey(),
        ]);
        $request->session()->regenerateToken();

        $this->auditEventService->success(
            $request,
            AuditEventType::MigrantDocumentDownloadChallengeStarted,
            $actor,
            ['type' => MigrantRegistryDocument::class, 'id' => $migrantRegistryDocument->getKey()],
            ['registryEntryId' => $migrantRegistryEntry->getKey(), 'challengeIntentId' => $challengeIntent->getKey()],
        );

        return response()->json([
            'message' => 'Migrant document download challenge created.',
            'options' => [
                'challenge' => $challenge,
                'rpId' => $originHost,
                'timeout' => 60000,
                'userVerification' => 'preferred',
                'allowCredentials' => $credentials->map(fn ($credential) => [
                    'id' => $credential->credential_id,
                    'type' => 'public-key',
                    'transports' => $credential->transports,
                ])->values(),
            ],
            'challengeIntent' => [
                'id' => $challengeIntent->getKey(),
                'purpose' => $challengeIntent->purpose,
                'status' => $challengeIntent->status,
                'expiresAt' => $challengeIntent->expires_at?->toIso8601String(),
            ],
        ]);
    }

    public function documentHash(MigrantRegistryDocument $document): string
    {
        return hash('sha256', json_encode([
            'id' => $document->getKey(),
            'originalFileName' => $document->original_file_name,
            'mimeType' => $document->mime_type,
            'sizeBytes' => $document->size_bytes,
            'sha256' => $document->sha256,
            'storageDisk' => $document->storage_disk,
            'storagePath' => $document->storage_path,
            'purgedAt' => $document->purged_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    private function authorizeDocument(MigrantRegistryEntry $entry, MigrantRegistryDocument $document): void
    {
        abort_unless($document->registry_entry_id === $entry->getKey(), 404);
    }

    private function assertDocumentAvailable(MigrantRegistryDocument $document): void
    {
        abort_if(
            $document->purged_at !== null ||
            ! $document->storage_path ||
            ! Storage::disk($document->storage_disk ?? 'local')->exists($document->storage_path),
            410,
            'This document is no longer available.',
        );
    }
}
