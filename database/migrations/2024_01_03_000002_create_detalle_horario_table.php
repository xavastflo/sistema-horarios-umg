<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_horario', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_detalle_horario')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK → horario
            $table->unsignedInteger('id_horario');
            // SQL oficial: int(10) UNSIGNED FK → asignacion_docente_curso
            $table->unsignedInteger('id_asignacion_docente_curso');
            // SQL oficial: tinyint(3) UNSIGNED FK → dia
            $table->unsignedTinyInteger('id_dia');
            // SQL oficial: int(10) UNSIGNED FK → bloque_horario
            $table->unsignedInteger('id_bloque_horario');
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // SQL oficial: UNIQUE(id_horario, id_bloque_horario)
            // Garantiza que un bloque no se use dos veces dentro del mismo horario
            $table->unique(
                ['id_horario', 'id_bloque_horario'],
                'uq_detalle_bloque'
            );

            // SQL oficial: KEY fk_detalle_asignacion, fk_detalle_dia, fk_detalle_bloque
            $table->index('id_asignacion_docente_curso', 'fk_detalle_asignacion');
            $table->index('id_dia',                      'fk_detalle_dia');
            $table->index('id_bloque_horario',           'fk_detalle_bloque');

            $table->foreign('id_horario', 'fk_detalle_horario')
                ->references('id_horario')->on('horario')
                ->cascadeOnDelete();

            $table->foreign('id_asignacion_docente_curso', 'fk_detalle_asignacion_fk')
                ->references('id_asignacion_docente_curso')->on('asignacion_docente_curso')
                ->restrictOnDelete();

            $table->foreign('id_dia', 'fk_detalle_dia_fk')
                ->references('id_dia')->on('dia')
                ->restrictOnDelete();

            $table->foreign('id_bloque_horario', 'fk_detalle_bloque_fk')
                ->references('id_bloque_horario')->on('bloque_horario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detalle_horario');
    }
};
