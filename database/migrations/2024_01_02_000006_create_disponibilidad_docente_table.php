<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disponibilidad_docente', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_disponibilidad_docente')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_docente');
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_bloque_horario');
            // SQL oficial: varchar(200) NULL
            $table->string('observacion', 200)->nullable();
            // SQL oficial: ENUM('activo','inactivo')
            // NOTA: El SQL oficial NO tiene campo tipo_disponibilidad
            // El ERD lo mencionaba pero el SQL oficial es la fuente de verdad
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            // SQL oficial: fecha_registro (no fecha_creacion)
            $table->dateTime('fecha_registro')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // SQL oficial: UNIQUE(id_docente, id_bloque_horario)
            // Un docente no puede marcar el mismo bloque dos veces
            $table->unique(
                ['id_docente', 'id_bloque_horario'],
                'uq_disponibilidad_docente_bloque'
            );
            $table->index('id_bloque_horario', 'fk_disponibilidad_bloque');

            $table->foreign('id_docente', 'fk_disponibilidad_docente')
                ->references('id_docente')->on('docente')
                ->cascadeOnDelete();

            $table->foreign('id_bloque_horario', 'fk_disponibilidad_bloque_fk')
                ->references('id_bloque_horario')->on('bloque_horario')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disponibilidad_docente');
    }
};
