<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\IngredienteController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'session.current'])->group(function (): void {
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

        Route::apiResource('ingredients', IngredienteController::class)
            ->parameters(['ingredients' => 'ingrediente']);

        Route::apiResource('categories', CategoriaController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['categories' => 'categoria']);

        Route::apiResource('products', ProductoController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['products' => 'producto']);
    });
});
