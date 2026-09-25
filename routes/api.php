<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::middleware('user.active')->group(function (): void {
        Route::get('/user', [AuthController::class, 'user'])
            ->name('auth.user');

        Route::post('/users', [UserController::class, 'store'])
            ->name('users.store');

        Route::get('/users', [UserController::class, 'index'])
            ->name('users.index');

        Route::patch('/users/{user}', [UserController::class, 'update'])
            ->name('users.update');

        Route::patch(
            '/users/{user}/estado',
            [UserController::class, 'updateStatus']
        )->name('users.update-status');
    });
});
