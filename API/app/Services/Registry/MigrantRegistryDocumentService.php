<?php

namespace App\Services\Registry;

use App\Enums\AuditEventType;
use App\Models\MigrantRegistryDocument;
use App\Models\MigrantRegistryEntry;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MigrantRegistryDocumentService
{
    /** Statuses where a registration is still editable and documents can be managed. */
    private const PRE_APPROVAL_STATUSES = [
        MigrantRegistryService::STATUS_PENDING_REVIEW,
        MigrantRegistryService::STATUS_PENDING_APPROVAL,
        MigrantRegistryService::STATUS_CHANGES_REQUESTED,
    ];

    /** Statuses a volunteer can still touch on their own registration. */
    private const VOLUNTEER_STATUSES = [
        MigrantRegistryService::STATUS_PENDING_REVIEW,
        MigrantRegistryService::STATUS_CHANGES_REQUESTED,
    ];

    public function __construct(
        private readonly AuditEventService $auditService,
        private readonly Request $request,
    ) {}

    public function store(User $actor, MigrantRegistryEntry $entry, UploadedFile $file, ?string $label): MigrantRegistryDocument
    {
        $originalFileName = basename($file->getClientOriginalName());
        $storedPath = null;

        try {
            $document = DB::transaction(function () use ($actor, $entry, $file, $label, $originalFileName, &$storedPath): MigrantRegistryDocument {
                $lockedEntry = MigrantRegistryEntry::query()
                    ->whereKey($entry->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();
                $this->assertCanUpload($actor, $lockedEntry);
                $max = (int) config('features.migrant_documents_max_per_entry', 10);

                if ($lockedEntry->documents()->whereNull('purged_at')->count() >= $max) {
                    abort(422, "This registration already has the maximum of {$max} documents.");
                }

                $storedPath = sprintf(
                    'migrant-registry/%d/documents/%s-%s',
                    $lockedEntry->getKey(),
                    Str::uuid()->toString(),
                    $originalFileName,
                );

                $stored = Storage::disk('local')->putFileAs(
                    dirname($storedPath),
                    $file,
                    basename($storedPath),
                );

                if ($stored === false) {
                    throw new RuntimeException('The migrant document file could not be stored.');
                }

                return MigrantRegistryDocument::query()->create([
                    'registry_entry_id' => $lockedEntry->getKey(),
                    'label' => $label !== null && trim($label) !== '' ? trim($label) : null,
                    'original_file_name' => $originalFileName,
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'sha256' => hash_file('sha256', $file->getRealPath()),
                    'storage_disk' => 'local',
                    'storage_path' => $storedPath,
                    'uploaded_by' => $actor->getKey(),
                    'uploaded_by_role' => $actor->role?->value ?? 'volunteer',
                ]);
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && Storage::disk('local')->exists($storedPath)) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }

        $this->auditService->success(
            $this->request,
            AuditEventType::MigrantDocumentUploaded,
            $actor,
            ['type' => MigrantRegistryDocument::class, 'id' => $document->getKey()],
            [
                'registryEntryId' => $entry->getKey(),
                'originalFileName' => $document->original_file_name,
                'mimeType' => $document->mime_type,
                'sizeBytes' => $document->size_bytes,
                'sha256' => $document->sha256,
                'label' => $document->label,
            ],
        );

        return $document;
    }

    public function delete(User $actor, MigrantRegistryEntry $entry, MigrantRegistryDocument $document): void
    {
        $this->assertCanDelete($actor, $entry);

        $this->deleteStoredFileOrFail($document);

        DB::transaction(function () use ($document): void {
            $document->forceFill([
                'storage_disk' => null,
                'storage_path' => null,
                'purged_at' => now(),
            ])->save();
            $document->delete();
        });

        $this->auditService->success(
            $this->request,
            AuditEventType::MigrantDocumentDeleted,
            $actor,
            ['type' => MigrantRegistryDocument::class, 'id' => $document->getKey()],
            [
                'registryEntryId' => $entry->getKey(),
                'originalFileName' => $document->original_file_name,
                'sha256' => $document->sha256,
            ],
        );
    }

    /** @param Collection<int, MigrantRegistryDocument> $documents */
    public function cleanupStoredFiles(Collection $documents): void
    {
        $documents->each(function (MigrantRegistryDocument $document): void {
            if ($document->storage_disk && $document->storage_path) {
                Storage::disk($document->storage_disk)->delete($document->storage_path);
            }
        });
    }

    public function deleteStoredFileOrFail(MigrantRegistryDocument $document): void
    {
        if (! $document->storage_disk || ! $document->storage_path) {
            return;
        }

        $disk = Storage::disk($document->storage_disk);

        if ($disk->exists($document->storage_path) && ! $disk->delete($document->storage_path)) {
            throw new RuntimeException('The migrant document file could not be deleted.');
        }
    }

    private function assertCanUpload(User $actor, MigrantRegistryEntry $entry): void
    {
        $role = $actor->role?->value;
        $status = (string) $entry->current_status;

        $allowed = match ($role) {
            'admin', 'coordinator' => in_array($status, self::PRE_APPROVAL_STATUSES, true)
                || $status === MigrantRegistryService::STATUS_APPROVED,
            'non_coordinator' => in_array($status, self::PRE_APPROVAL_STATUSES, true),
            'volunteer' => $entry->created_by === $actor->getKey()
                && in_array($status, self::VOLUNTEER_STATUSES, true),
            default => false,
        };

        abort_unless($allowed, 403, 'You are not allowed to add documents to this registration in its current state.');
    }

    private function assertCanDelete(User $actor, MigrantRegistryEntry $entry): void
    {
        $role = $actor->role?->value;
        $status = (string) $entry->current_status;

        if (! in_array($status, self::PRE_APPROVAL_STATUSES, true)) {
            abort(403, 'Documents can only be removed while the registration is still under review.');
        }

        $allowed = match ($role) {
            'admin', 'coordinator', 'non_coordinator' => true,
            'volunteer' => $entry->created_by === $actor->getKey()
                && in_array($status, self::VOLUNTEER_STATUSES, true),
            default => false,
        };

        abort_unless($allowed, 403, 'You are not allowed to remove documents from this registration.');
    }
}
