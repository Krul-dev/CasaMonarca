<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsrfTokenController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->session()->regenerateToken();

        return response()->json([
            'csrfToken' => $request->session()->token(),
        ]);
    }
}
