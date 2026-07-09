<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvatarController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {

    // Public routes — no login required
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Requires a valid Sanctum token — any authenticated role
    Route::middleware('auth:sanctum')->group(function () {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // Avatar upload/remove — works for member, librarian, admin alike
        Route::post('profile/avatar', [AvatarController::class, 'update']);
        Route::delete('profile/avatar', [AvatarController::class, 'destroy']);

        // Requires token + role = admin
        Route::middleware('role:admin')->group(function () {
            Route::post('create-librarian', [AuthController::class, 'createLibrarian']);
        });
    });
});