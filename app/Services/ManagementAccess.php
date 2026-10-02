<?php

namespace App\Services;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ManagementAccess
{
    /**
     * All role and user mutations lock roles before users. This serializes
     * permission changes, renames and removals of the last manager across servers.
     *
     * @return Collection<int, Rol>
     */
    public static function lockRoles(): Collection
    {
        return Rol::query()->orderBy('id_rol')->lockForUpdate()->get();
    }

    /** @param Collection<int, Rol> $roles */
    public static function assertManagerRemains(Collection $roles, string $field): void
    {
        $names = $roles->filter(fn (Rol $role): bool => $role->grantsManagement())->pluck('nombre');
        if (! User::query()->where('estado', 'ACTIVO')->whereIn('rol', $names)->exists()) {
            throw ValidationException::withMessages([
                $field => 'Debe permanecer al menos un usuario activo con un rol activo y todos los permisos de Usuarios y Roles.',
            ]);
        }
    }
}
