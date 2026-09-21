<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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

    public function bitacoras(): HasMany
    {
        return $this->hasMany(
            Bitacora::class,
            'id_usuario',
            'id_usuario'
        );
    }
}