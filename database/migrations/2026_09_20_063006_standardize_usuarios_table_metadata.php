<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre_completo', 100)->change();
        });

        DB::statement(
            'ALTER TABLE usuarios RENAME CONSTRAINT users_pkey TO usuarios_pkey'
        );

        DB::statement(
            'ALTER SEQUENCE users_id_seq RENAME TO usuarios_id_usuario_seq'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE usuarios RENAME CONSTRAINT usuarios_pkey TO users_pkey'
        );

        DB::statement(
            'ALTER SEQUENCE usuarios_id_usuario_seq RENAME TO users_id_seq'
        );

        Schema::table('usuarios', function (Blueprint $table) {
            $table->string('nombre_completo', 255)->change();
        });
    }
};