<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id_rol');
            $table->string('nombre', 30)->unique();
            $table->json('permisos');
            $table->string('estado', 20)->default('ACTIVO');
            $table->boolean('es_sistema')->default(false);
        });

        $modules = ['usuarios', 'roles', 'categorias', 'productos', 'ingredientes', 'combos', 'pedidos', 'cocina'];
        $actions = ['crear', 'ver', 'editar', 'eliminar'];
        foreach (['ADMINISTRADOR', 'TI', 'CAJA', 'COCINA'] as $name) {
            $permissions = array_fill_keys($modules, array_fill_keys($actions, false));
            foreach (match ($name) {
                'ADMINISTRADOR' => $modules,
                'TI' => ['usuarios', 'roles', 'ingredientes'],
                default => [],
            } as $module) {
                $permissions[$module] = array_fill_keys($actions, true);
            }
            if ($name === 'CAJA') {
                $permissions['pedidos']['ver'] = true;
            } elseif ($name === 'COCINA') {
                $permissions['cocina']['ver'] = true;
            }
            DB::table('roles')->insert([
                'nombre' => $name, 'permisos' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'estado' => 'ACTIVO', 'es_sistema' => true,
            ]);
        }
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE usuarios DROP CONSTRAINT IF EXISTS usuarios_rol_valores_permitidos_check');
        }
        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreign('rol')->references('nombre')->on('roles')->restrictOnDelete()->cascadeOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('usuarios')->whereNotIn('rol', ['ADMINISTRADOR', 'TI', 'CAJA', 'COCINA'])->exists()) {
            throw new RuntimeException('Reasigne los usuarios de roles personalizados antes de revertir esta migración.');
        }
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropForeign(['rol']);
        });
        Schema::dropIfExists('roles');
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE usuarios ADD CONSTRAINT usuarios_rol_valores_permitidos_check CHECK (rol IN ('ADMINISTRADOR', 'TI', 'CAJA', 'COCINA'))");
        }
    }
};
