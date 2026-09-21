<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/user', [AuthController::class, 'user'])
        ->name('auth.user');

    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');
});