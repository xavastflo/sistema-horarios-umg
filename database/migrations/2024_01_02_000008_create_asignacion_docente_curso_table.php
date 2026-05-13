<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asignacion_docente_curso', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_asignacion_docente_curso')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_docente');
            // SQL oficial: int(10) UNSIGNED FK
            // UNIQUE en id_seccion: una sección solo puede tener UN docente activo
            $table->unsignedInteger('id_seccion')->unique();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_asignacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->index('id_docente', 'fk_asignacion_docente');

            $table->foreign('id_docente', 'fk_asignacion_docente_fk')
                ->references('id_docente')->on('docente')
                ->restrictOnDelete();

            $table->foreign('id_seccion', 'fk_asignacion_seccion')
                ->references('id_seccion')->on('seccion')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignacion_docente_curso');
    }
};
