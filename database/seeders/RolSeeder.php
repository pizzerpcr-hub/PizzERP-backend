<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (Rol::query()->exists()) {
            return;
        }
        foreach (User::ROLES as $name) {
            DB::table('roles')->insertOrIgnore([
                'nombre' => $name,
                'permisos' => json_encode(Rol::systemPermissions($name), JSON_THROW_ON_ERROR),
                'estado' => 'ACTIVO', 'es_sistema' => true,
            ]);
        }
    }
}
