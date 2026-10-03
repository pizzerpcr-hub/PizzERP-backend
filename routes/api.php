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

        Route::get('/permissions', function (Request $request) {
            $user = $request->user()->loadMissing('assignedRole');

            return response()->json([
                'permisos' => $user->modulePermissions(),
                'rol_id' => $user->assignedRole?->getKey(),
            ]);
        })->name('auth.permissions');

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

        Route::patch('/ingredients/{ingrediente}/estado', [IngredienteController::class, 'updateStatus'])
            ->name('ingredients.update-status');
        Route::apiResource('ingredients', IngredienteController::class)
            ->parameters(['ingredients' => 'ingrediente']);

        Route::apiResource('categories', CategoriaController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['categories' => 'categoria']);

        Route::apiResource('products', ProductoController::class)
            ->only(['index', 'store', 'update'])
            ->parameters(['products' => 'producto']);
        Route::patch('/products/{producto}/estado', [ProductoController::class, 'updateStatus'])
            ->name('products.update-status');

        Route::get('/products/categorias', [ProductoController::class, 'selectableCategories'])->name('products.selectable-categories');
        Route::get('/products/ingredientes', [ProductoController::class, 'selectableIngredients'])->name('products.selectable-ingredients');
    });
});
