<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('users', 'usuarios');

        Schema::table('usuarios', function (Blueprint $table) {
            $table->renameColumn('id', 'id_usuario');
            $table->renameColumn('name', 'nombre_completo');
            $table->renameColumn('password', 'contrasena_hash');
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre_usuario', 50)->unique();
            $table->string('rol', 30);
            $table->string('estado', 20)->default('ACTIVO');
            $table->unsignedSmallInteger('intentos_fallidos')->default(0);
            $table->timestampTz('bloqueado_hasta')->nullable();

            $table->dropColumn([
                'email',
                'email_verified_at',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_usuario',
                'rol',
                'estado',
                'intentos_fallidos',
                'bloqueado_hasta',
            ]);

            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
        });

        Schema::table('usuarios', function (Blueprint $table) {
            $table->renameColumn('id_usuario', 'id');
            $table->renameColumn('nombre_completo', 'name');
            $table->renameColumn('contrasena_hash', 'password');
        });

        Schema::rename('usuarios', 'users');
    }
};