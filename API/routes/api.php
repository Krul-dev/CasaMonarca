<?php

use App\Http\Controllers\Api\Auth\CsrfTokenController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware('web')->group(function (): void {
    Route::get('/csrf-token', CsrfTokenController::class);
    Route::post('/login', LoginController::class);
});
