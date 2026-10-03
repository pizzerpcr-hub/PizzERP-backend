<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait BuildsManagementSchema
{
    protected function buildManagementSchema(): void
    {
        Schema::create('usuarios', function (Blueprint $table): void {
            $table->id('id_usuario');
            $table->string('nombre_completo', 100);
            $table->string('nombre_usuario', 50)->unique();
            $table->string('contrasena_hash');
            $table->string('rol', 30);
            $table->string('estado', 20)->default('ACTIVO');
            $table->unsignedSmallInteger('intentos_fallidos')->default(0);
            $table->timestamp('bloqueado_hasta')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('bitacoras', function (Blueprint $table): void {
            $table->id('id_bitacora');
            $table->foreignId('id_usuario')->constrained('usuarios', 'id_usuario')->restrictOnDelete();
            $table->string('descripcion_movimiento', 255);
            $table->string('tipo_movimiento', 50);
            $table->string('motivo', 255);
            $table->timestamp('fecha')->useCurrent();
        });
        foreach ([
            '2026_10_01_211033_create_ingredientes_table.php',
            '2026_10_01_221612_create_categorias_table.php',
            '2026_10_01_221613_create_productos_table.php',
            '2026_10_02_012949_create_roles_table_and_link_usuarios.php',
            '2026_10_02_012950_create_combos_table.php',
            '2026_10_02_213313_create_producto_ingredientes_table.php',
            '2026_10_03_031333_add_unidad_medida_to_producto_ingredientes_table.php',
        ] as $filename) {
            (require database_path('migrations/'.$filename))->up();
        }
    }
}
