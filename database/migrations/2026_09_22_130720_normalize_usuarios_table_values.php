<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'UPDATE usuarios
            SET nombre_usuario = UPPER(TRIM(nombre_usuario)),
                rol = UPPER(TRIM(rol)),
                estado = UPPER(TRIM(estado))'
        );

        DB::statement(
            'ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_nombre_usuario_uppercase_trimmed_check
            CHECK (nombre_usuario = UPPER(TRIM(nombre_usuario)))'
        );

        DB::statement(
            'ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_rol_valores_permitidos_check
            CHECK (rol IN (\'ADMINISTRADOR\', \'CAJA\', \'COCINA\', \'TI\'))'
        );

        DB::statement(
            'ALTER TABLE usuarios
            ADD CONSTRAINT usuarios_estado_valores_permitidos_check
            CHECK (estado IN (\'ACTIVO\', \'INACTIVO\'))'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE usuarios
            DROP CONSTRAINT IF EXISTS usuarios_nombre_usuario_uppercase_trimmed_check'
        );

        DB::statement(
            'ALTER TABLE usuarios
            DROP CONSTRAINT IF EXISTS usuarios_rol_valores_permitidos_check'
        );

        DB::statement(
            'ALTER TABLE usuarios
            DROP CONSTRAINT IF EXISTS usuarios_estado_valores_permitidos_check'
        );
    }
};
