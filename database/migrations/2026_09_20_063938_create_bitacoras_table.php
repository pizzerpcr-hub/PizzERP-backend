<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bitacoras', function (Blueprint $table) {
            $table->id('id_bitacora');

            $table->foreignId('id_usuario')
                ->constrained('usuarios', 'id_usuario')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->string('descripcion_movimiento', 255);
            $table->string('tipo_movimiento', 50);
            $table->string('motivo', 255);
            $table->timestamp('fecha')->useCurrent();

            $table->index('id_usuario');
            $table->index('fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bitacoras');
    }
};