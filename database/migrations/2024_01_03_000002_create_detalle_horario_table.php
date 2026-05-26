<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla detalle_horario.
 *
 * DECISIÓN ARQUITECTÓNICA — Sin restricciones UNIQUE sobre id_bloque_horario:
 *
 *   Razón 1 — Paralelismo entre ciclos:
 *     El mismo id_bloque_horario puede aparecer en múltiples filas del mismo
 *     id_horario si pertenece a ciclos distintos con docentes distintos.
 *     (Aulas fuera del alcance del proyecto.)
 *
 *   Razón 2 — Regeneración con estado inactivo:
 *     El sistema inactiva registros en lugar de borrarlos físicamente.
 *     Un UNIQUE chocaría contra registros inactivos al regenerar la misma
 *     combinación en una nueva generación.
 *
 *   Por tanto NO existe ningún UNIQUE sobre id_bloque_horario.
 *   La prevención de duplicados activos se delega completamente a
 *   ConflictValidationService::validarBloqueEnHorario() filtrando estado = 'activo'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detalle_horario', function (Blueprint $table) {
            $table->unsignedInteger('id_detalle_horario')->autoIncrement();
            $table->unsignedInteger('id_horario');
            $table->unsignedInteger('id_asignacion_docente_curso');
            $table->unsignedTinyInteger('id_dia');
            $table->unsignedInteger('id_bloque_horario');
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // ── Índices de rendimiento (sin UNIQUE) ─────────────────────────

            // Consultas por horario + bloque (sin UNIQUE — paralelismo + regeneración)
            $table->index(
                ['id_horario', 'id_bloque_horario'],
                'idx_detalle_horario_bloque'
            );

            // Consultas de duplicidad activa por asignación + bloque
            $table->index(
                ['id_horario', 'id_asignacion_docente_curso', 'id_bloque_horario'],
                'idx_detalle_asignacion_bloque'
            );

            $table->index('id_asignacion_docente_curso', 'fk_detalle_asignacion');
            $table->index('id_dia',                      'fk_detalle_dia');
            $table->index('id_bloque_horario',           'fk_detalle_bloque');

            // ── Foreign keys ────────────────────────────────────────────────
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
