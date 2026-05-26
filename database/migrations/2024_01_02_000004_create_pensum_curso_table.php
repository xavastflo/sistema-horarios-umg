<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pensum_curso', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_pensum_curso')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_pensum');
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_curso');
            // SQL oficial: tinyint(3) UNSIGNED — número de ciclo/semestre
            $table->unsignedTinyInteger('ciclo_semestre');
            // Bloques semanales: cuántos bloques horarios requiere este curso por semana
            // en el contexto de este pensum. Origen de verdad para el algoritmo de horarios.
            // Default 1 — mínimo 1, máximo 10.
            $table->unsignedTinyInteger('bloques_semanales')->default(1);
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();

            // SQL oficial: UNIQUE(id_pensum, id_curso) — un curso no se repite en el mismo pensum
            $table->unique(['id_pensum', 'id_curso'], 'uq_pensum_curso');
            $table->index('id_curso', 'fk_pensum_curso_curso');

            $table->foreign('id_pensum', 'fk_pensum_curso_pensum')
                ->references('id_pensum')->on('pensum')
                ->cascadeOnDelete();

            $table->foreign('id_curso', 'fk_pensum_curso_curso_fk')
                ->references('id_curso')->on('curso')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pensum_curso');
    }
};
