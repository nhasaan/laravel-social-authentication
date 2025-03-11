<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\SocialAuthController;

// Social Auth routes (no authentication middleware)
Route::prefix('auth/social')->group(function () {
    Route::post('url', [SocialAuthController::class, 'getAuthUrl']);
    Route::post('callback', [SocialAuthController::class, 'handleCallback']);
});