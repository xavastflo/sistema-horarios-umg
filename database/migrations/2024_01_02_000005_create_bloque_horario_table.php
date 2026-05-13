<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bloque_horario', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_bloque_horario')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_carrera_jornada');
            // SQL oficial: tinyint(3) UNSIGNED FK
            $table->unsignedTinyInteger('id_dia');
            // SQL oficial: time NOT NULL
            $table->time('hora_inicio');
            $table->time('hora_fin');
            // SQL oficial: smallint(5) UNSIGNED
            $table->unsignedSmallInteger('duracion_minutos');
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // SQL oficial: UNIQUE(id_carrera_jornada, id_dia, hora_inicio, hora_fin)
            $table->unique(
                ['id_carrera_jornada', 'id_dia', 'hora_inicio', 'hora_fin'],
                'uq_bloque_horario'
            );
            $table->index('id_dia', 'fk_bloque_dia');

            $table->foreign('id_carrera_jornada', 'fk_bloque_carrera_jornada')
                ->references('id_carrera_jornada')->on('carrera_jornada')
                ->restrictOnDelete();

            $table->foreign('id_dia', 'fk_bloque_dia_fk')
                ->references('id_dia')->on('dia')
                ->restrictOnDelete();
        });
        // SQL oficial: no declara ENGINE — Laravel usa InnoDB por defecto
    }

    public function down(): void
    {
        Schema::dropIfExists('bloque_horario');
    }
};
