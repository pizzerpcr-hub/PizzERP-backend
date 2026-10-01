<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredientes', function (Blueprint $table) {
            $table->increments('id_ingrediente');
            $table->string('nombre', 100);
            $table->string('unidad_medida', 30);
            $table->decimal('cantidad_disponible', 10, 2)->default(0);
            $table->string('estado', 20)->default('ACTIVO');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredientes');
    }
};
