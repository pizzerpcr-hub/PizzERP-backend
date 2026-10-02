<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLES = [
        'ADMINISTRADOR',
        'CAJA',
        'COCINA',
        'TI',
    ];

    protected $table = 'usuarios';

    protected $primaryKey = 'id_usuario';

    protected $fillable = [
        'nombre_completo',
        'nombre_usuario',
        'contrasena_hash',
        'rol',
        'estado',
        'intentos_fallidos',
        'bloqueado_hasta',
    ];

    protected $hidden = [
        'contrasena_hash',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'contrasena_hash' => 'hashed',
            'intentos_fallidos' => 'integer',
            'bloqueado_hasta' => 'datetime',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'contrasena_hash';
    }

    public function canManageUsers(): bool
    {
        return $this->hasModulePermission('usuarios', 'ver');
    }

    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol', 'nombre');
    }

    /** @return array<string, array<string, bool>> */
    public function modulePermissions(): array
    {
        if (mb_strtoupper(trim((string) $this->estado)) !== 'ACTIVO') {
            return Rol::emptyPermissions();
        }
        $name = mb_strtoupper(trim((string) $this->rol));
        $request = app('request');
        $key = 'effective_role_permissions.'.spl_object_id($this).'.'.$name;
        if (! $request->attributes->has($key)) {
            $role = $this->relationLoaded('assignedRole') ? $this->assignedRole : $this->assignedRole()->first();
            $request->attributes->set($key, $role?->estado === 'ACTIVO'
                ? $role->permisos : Rol::emptyPermissions());
        }

        return $request->attributes->get($key);
    }

    public function hasModulePermission(string $module, string $action): bool
    {
        return ($this->modulePermissions()[$module][$action] ?? false) === true;
    }

    /** @param array<string, array<string, bool>> $permissions */
    public function mayDelegatePermissions(array $permissions): bool
    {
        $own = $this->modulePermissions();
        foreach ($permissions as $module => $actions) {
            foreach ($actions as $action => $allowed) {
                if ($allowed && ! ($own[$module][$action] ?? false)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function bitacoras(): HasMany
    {
        return $this->hasMany(
            Bitacora::class,
            'id_usuario',
            'id_usuario'
        );
    }
}
