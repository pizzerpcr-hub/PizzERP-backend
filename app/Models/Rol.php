<?php

namespace App\Models;

use Database\Factories\RolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rol extends Model
{
    /** @use HasFactory<RolFactory> */
    use HasFactory;

    public const MODULES = ['usuarios', 'roles', 'categorias', 'productos', 'ingredientes', 'combos', 'pedidos', 'cocina'];

    public const ACTIONS = ['crear', 'ver', 'editar', 'eliminar'];

    protected $table = 'roles';

    protected $primaryKey = 'id_rol';

    public $timestamps = false;

    protected $fillable = ['nombre', 'permisos', 'estado'];

    protected $attributes = ['es_sistema' => false, 'estado' => 'ACTIVO'];

    protected function casts(): array
    {
        return ['permisos' => 'array', 'es_sistema' => 'boolean'];
    }

    public function usuarios(): HasMany
    {
        return $this->hasMany(User::class, 'rol', 'nombre');
    }

    public function grantsManagement(): bool
    {
        if ($this->estado !== 'ACTIVO') {
            return false;
        }
        foreach (['usuarios', 'roles'] as $module) {
            foreach (self::ACTIONS as $action) {
                if (($this->permisos[$module][$action] ?? false) !== true) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @return array<string, array<string, bool>> */
    public static function emptyPermissions(): array
    {
        return array_fill_keys(self::MODULES, array_fill_keys(self::ACTIONS, false));
    }

    /** @return array<string, array<string, bool>> */
    public static function systemPermissions(string $name): array
    {
        $permissions = self::emptyPermissions();
        $modules = match ($name) {
            'ADMINISTRADOR' => self::MODULES,
            'TI' => ['usuarios', 'roles', 'ingredientes'],
            default => [],
        };
        foreach ($modules as $module) {
            $permissions[$module] = array_fill_keys(self::ACTIONS, true);
        }
        if ($name === 'CAJA') {
            $permissions['pedidos']['ver'] = true;
        }
        if ($name === 'COCINA') {
            $permissions['cocina']['ver'] = true;
        }

        return $permissions;
    }

    /** @return array<string, array<int, string>> */
    public static function permissionRules(): array
    {
        $rules = ['permisos' => ['required', 'array:'.implode(',', self::MODULES)]];
        foreach (self::MODULES as $module) {
            $rules['permisos.'.$module] = ['required', 'array:'.implode(',', self::ACTIONS)];
            foreach (self::ACTIONS as $action) {
                $rules['permisos.'.$module.'.'.$action] = ['required', 'boolean:strict'];
            }
        }

        return $rules;
    }
}
