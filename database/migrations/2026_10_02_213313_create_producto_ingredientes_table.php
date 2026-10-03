<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('producto_ingredientes', function (Blueprint $table) {
            $table->increments('id_producto_ingrediente');
            $table->unsignedInteger('id_producto');
            $table->unsignedInteger('id_ingrediente');
            $table->decimal('cantidad_requerida', 10, 2);

            $table->unique(['id_producto', 'id_ingrediente']);
            $table->index('id_ingrediente');
            $table->foreign('id_producto')->references('id_producto')->on('productos')->cascadeOnDelete();
            $table->foreign('id_ingrediente')->references('id_ingrediente')->on('ingredientes')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producto_ingredientes');
    }
};
