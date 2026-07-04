<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Admin\UserDirectoryViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserIndexController extends Controller
{
    public function __construct(
        private readonly UserDirectoryViewService $userDirectoryViewService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with(['browserDevices' => fn ($query) => $query->latest('last_seen_at')])
            ->withCount('webauthnCredentials')
            ->withCount('browserDevices')
            ->latest('id')
            ->limit((int) ($validated['limit'] ?? 50))
            ->get();

        return response()->json([
            'message' => 'Users loaded successfully.',
            'users' => $users->map(
                fn (User $user): array => $this->userDirectoryViewService->serialize($user),
            )->values(),
        ]);
    }
}
