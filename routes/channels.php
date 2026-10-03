<?php

use App\Events\ModuleDataChanged;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('crud.usuario.{id}.{module}', function (User $user, string $id, string $module): bool {
    return (string) $user->getKey() === $id
        && in_array($module, ModuleDataChanged::MODULES, true)
        && $user->estado === 'ACTIVO'
        && $user->hasModulePermission($module, 'ver');
});

Broadcast::channel('usuario.{id}', function (User $user, string $id): bool {
    return (string) $user->getKey() === $id
        && $user->estado === 'ACTIVO';
});

Broadcast::channel('rol.{id}', function (User $user, string $id): bool {
    return $user->estado === 'ACTIVO'
        && $user->assignedRole()->whereKey($id)->exists();
});
