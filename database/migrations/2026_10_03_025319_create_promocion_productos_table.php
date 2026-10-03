<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promocion_productos', function (Blueprint $table) {
            $table->increments('id_promocion_producto');
            $table->unsignedInteger('id_promocion');
            $table->unsignedInteger('id_producto');
            $table->unsignedInteger('cantidad');

            $table->unique(['id_promocion', 'id_producto']);
            $table->index('id_producto');
            $table->foreign('id_promocion')->references('id_combo')->on('combos')->cascadeOnDelete();
            $table->foreign('id_producto')->references('id_producto')->on('productos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocion_productos');
    }
};
