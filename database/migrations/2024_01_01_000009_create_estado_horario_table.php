<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estado_horario', function (Blueprint $table) {
            // SQL oficial: tinyint(3) UNSIGNED AUTO_INCREMENT
            $table->unsignedTinyInteger('id_estado_horario')->autoIncrement();
            // SQL oficial: varchar(30)
            $table->string('nombre_estado', 30)->unique();
            // SQL oficial: varchar(150)
            $table->string('descripcion', 150)->nullable();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estado_horario');
    }
};
