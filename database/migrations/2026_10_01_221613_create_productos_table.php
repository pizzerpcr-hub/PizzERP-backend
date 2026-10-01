<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productos', function (Blueprint $table) {
            $table->increments('id_producto');
            $table->unsignedInteger('id_categoria');
            $table->string('codigo_producto', 30)->unique();
            $table->string('nombre', 100);
            $table->string('descripcion', 150);
            $table->decimal('precio', 10, 2);
            $table->string('estado', 20)->default('ACTIVO');

            $table->foreign('id_categoria')
                ->references('id_categoria')
                ->on('categorias')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productos');
    }
};
