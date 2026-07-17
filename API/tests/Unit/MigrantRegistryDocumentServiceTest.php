<?php

namespace Tests\Unit;

use App\Models\MigrantRegistryDocument;
use App\Services\Audit\AuditEventService;
use App\Services\Registry\MigrantRegistryDocumentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MigrantRegistryDocumentServiceTest extends TestCase
{
    public function test_failed_physical_deletion_stops_document_purge(): void
    {
        $disk = $this->createMock(FilesystemAdapter::class);
        $disk->expects($this->once())->method('exists')->with('registry/document.pdf')->willReturn(true);
        $disk->expects($this->once())->method('delete')->with('registry/document.pdf')->willReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($disk);
        $document = new MigrantRegistryDocument([
            'storage_disk' => 'local',
            'storage_path' => 'registry/document.pdf',
        ]);
        $service = new MigrantRegistryDocumentService(
            $this->createMock(AuditEventService::class),
            Request::create('/registry/migrants/1/documents/1', 'DELETE'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The migrant document file could not be deleted.');

        $service->deleteStoredFileOrFail($document);
    }
}
