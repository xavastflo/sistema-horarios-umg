<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rol', function (Blueprint $table) {
            // SQL oficial: tinyint(3) UNSIGNED AUTO_INCREMENT
            $table->unsignedTinyInteger('id_rol')->autoIncrement();
            $table->string('nombre_rol', 30)->unique();
            $table->string('descripcion', 150)->nullable();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rol');
    }
};
