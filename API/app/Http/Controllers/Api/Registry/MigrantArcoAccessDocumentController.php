<?php

namespace App\Http\Controllers\Api\Registry;

use App\Enums\AuditEventType;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\MigrantArcoRequest;
use App\Models\User;
use App\Services\Audit\AuditEventService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MigrantArcoAccessDocumentController extends Controller
{
    public function __construct(private readonly AuditEventService $audit) {}

    public function __invoke(Request $request, MigrantArcoRequest $migrantArcoRequest): StreamedResponse
    {
        abort_unless(in_array('access', config('features.arco_types', ['access']), true), 404);

        $actor = $request->user();
        if (! $actor instanceof User || ((int) $actor->id !== (int) $migrantArcoRequest->requested_by && ! in_array($actor->role, [UserRole::Coordinator, UserRole::Admin], true))) {
            abort(403);
        }
        $artifact = $migrantArcoRequest->artifact;
        if (! $artifact || ! $artifact->storage_disk || ! $artifact->storage_path || $artifact->purged_at || ! Storage::disk($artifact->storage_disk)->exists($artifact->storage_path)) {
            abort(404, 'The Access PDF is unavailable or has been purged.');
        }
        $this->audit->success($request, AuditEventType::MigrantArcoAccessDocumentDownloaded, $actor, ['type' => MigrantArcoRequest::class, 'id' => $migrantArcoRequest->id], ['arcoProcess' => true, 'requestType' => 'access', 'sha256' => $artifact->sha256]);

        return Storage::disk($artifact->storage_disk)->download($artifact->storage_path, $artifact->filename, ['Content-Type' => 'application/pdf']);
    }
}
