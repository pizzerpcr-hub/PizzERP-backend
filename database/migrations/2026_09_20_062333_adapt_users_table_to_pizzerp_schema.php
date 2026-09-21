<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users', 'usuarios');

        Schema::table('usuarios', function (Blueprint $table) {
            $table->renameColumn('id', 'id_usuario');
            $table->renameColumn(
                'contrasena',
                'contrasena_hash'
            );
        });

        DB::statement(
            'ALTER TABLE usuarios ALTER COLUMN estado DROP DEFAULT'
        );

        DB::statement(
            "ALTER TABLE usuarios
            ALTER COLUMN estado TYPE varchar(20)
            USING (
                CASE
                    WHEN estado THEN 'ACTIVO'
                    ELSE 'INACTIVO'
                END
            )"
        );

        DB::statement(
            "ALTER TABLE usuarios
            ALTER COLUMN estado SET DEFAULT 'ACTIVO'"
        );

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre_usuario', 50)->change();
            $table->string('rol', 30)->change();
            $table->rememberToken();
            $table->unsignedSmallInteger('intentos_fallidos')
                ->default(0);
            $table->timestampTz('bloqueado_hasta')
                ->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn([
                'remember_token',
                'intentos_fallidos',
                'bloqueado_hasta',
            ]);

            $table->string('nombre_usuario', 255)->change();
            $table->string('rol', 255)->change();
        });

        DB::statement(
            'ALTER TABLE usuarios ALTER COLUMN estado DROP DEFAULT'
        );

        DB::statement(
            "ALTER TABLE usuarios
            ALTER COLUMN estado TYPE boolean
            USING (estado = 'ACTIVO')"
        );

        DB::statement(
            'ALTER TABLE usuarios
            ALTER COLUMN estado SET DEFAULT true'
        );

        Schema::table('usuarios', function (Blueprint $table) {
            $table->renameColumn('id_usuario', 'id');
            $table->renameColumn(
                'contrasena_hash',
                'contrasena'
            );
        });

        Schema::rename('usuarios', 'users');
    }
};
