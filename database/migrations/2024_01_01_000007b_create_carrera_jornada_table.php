<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrera_jornada', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_carrera_jornada')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_carrera');
            // SQL oficial: tinyint(3) UNSIGNED FK
            $table->unsignedTinyInteger('id_jornada');
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();

            $table->unique(['id_carrera', 'id_jornada'], 'uq_carrera_jornada');
            $table->index('id_jornada', 'fk_carrera_jornada_jornada');

            $table->foreign('id_carrera', 'fk_carrera_jornada_carrera')
                ->references('id_carrera')->on('carrera')
                ->cascadeOnDelete();

            $table->foreign('id_jornada', 'fk_carrera_jornada_jornada_fk')
                ->references('id_jornada')->on('jornada')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrera_jornada');
    }
};
