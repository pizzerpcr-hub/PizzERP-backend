<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('usuario.{id}', function (User $user, string $id): bool {
    return (string) $user->getKey() === $id
        && $user->estado === 'ACTIVO';
});

Broadcast::channel('rol.{id}', function (User $user, string $id): bool {
    return $user->estado === 'ACTIVO'
        && $user->assignedRole()->whereKey($id)->exists();
});
