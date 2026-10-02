<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\CategoriaController;
use App\Http\Controllers\ComboController;
use App\Http\Controllers\IngredienteController;
use App\Http\Controllers\ProductoController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->name('auth.login');

Route::middleware(['auth:sanctum', 'session.current'])->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])
        ->name('auth.logout');

    Route::middleware('user.active')->group(function (): void {
        Route::get('/user', [AuthController::class, 'user'])
            ->name('auth.user');

        Route::get('/permissions', fn (Request $request) => response()->json([
            'permisos' => $request->user()->modulePermissions(),
        ]))->name('auth.permissions');

        Route::get('/combos/productos', [ComboController::class, 'selectableProducts'])->name('combos.selectable-products');
        Route::apiResource('combos', ComboController::class)->only(['index', 'store', 'show', 'update'])->whereNumber('combo');
        Route::patch('/combos/{combo}/estado', [ComboController::class, 'updateStatus'])->whereNumber('combo')->name('combos.update-status');
        Route::apiResource('roles', RolController::class)->only(['index', 'store', 'show', 'update'])
            ->parameters(['roles' => 'role'])->whereNumber('role');
        Route::patch('/roles/{role}/estado', [RolController::class, 'updateStatus'])->whereNumber('role')->name('roles.update-status');

        Route::post('/users', [UserController::class, 'store'])
            ->name('users.store');

        Route::get('/users/roles', [UserController::class, 'assignableRoles'])->name('users.assignable-roles');

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

        Route::get('/products/categorias', [ProductoController::class, 'selectableCategories'])->name('products.selectable-categories');
    });
});
