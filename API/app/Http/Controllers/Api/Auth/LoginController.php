<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * @throws ValidationException
     */
    public function __invoke(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->validated())) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        /** @var \App\Models\User $user */
        $user = $request->user();

        return response()->json([
            'message' => 'Login successful.',
            'user' => [
                'id' => $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);
    }
}
