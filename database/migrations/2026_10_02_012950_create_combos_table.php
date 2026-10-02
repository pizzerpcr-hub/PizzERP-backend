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
        Schema::create('combos', function (Blueprint $table) {
            $table->increments('id_combo');
            $table->string('codigo_combo', 30)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 150)->nullable();
            $table->decimal('precio', 10, 2);
            $table->string('estado', 20)->default('ACTIVO');
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
        });
        Schema::create('combo_producto', function (Blueprint $table) {
            $table->unsignedInteger('id_combo');
            $table->unsignedInteger('id_producto');
            $table->unsignedInteger('cantidad');
            $table->primary(['id_combo', 'id_producto']);
            $table->foreign('id_combo')->references('id_combo')->on('combos')->cascadeOnDelete();
            $table->foreign('id_producto')->references('id_producto')->on('productos')->restrictOnDelete();
            $table->index('id_producto');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('combo_producto');
        Schema::dropIfExists('combos');
    }
};
