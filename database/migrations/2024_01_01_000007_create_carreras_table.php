<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrera', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_carrera')->autoIncrement();
            // SQL oficial: smallint(5) UNSIGNED FK
            $table->unsignedSmallInteger('id_facultad');
            // SQL oficial: varchar(120)
            $table->string('nombre_carrera', 120);
            $table->string('codigo_carrera', 20)->unique();
            // SQL oficial: int(10) UNSIGNED FK NULL
            $table->unsignedInteger('id_usuario_coordinador')->nullable();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_asignacion_coordinador')->nullable();
            $table->dateTime('fecha_desasignacion_coordinador')->nullable();
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_facultad', 'fk_carrera_facultad')
                ->references('id_facultad')->on('facultad')
                ->restrictOnDelete();

            $table->foreign('id_usuario_coordinador', 'fk_carrera_coordinador')
                ->references('id_usuario')->on('usuario')
                ->nullOnDelete();

            $table->index('id_facultad', 'fk_carrera_facultad_idx');
            $table->index('id_usuario_coordinador', 'fk_carrera_coordinador_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrera');
    }
};
