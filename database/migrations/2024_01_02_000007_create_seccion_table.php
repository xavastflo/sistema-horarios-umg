<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seccion', function (Blueprint $table) {
            // 1. Declaración de todas las columnas primero
            $table->unsignedInteger('id_seccion')->autoIncrement();
            
            // Usamos unsignedInteger para garantizar coincidencia exacta con las tablas padre
            $table->unsignedInteger('id_carrera_jornada');
            $table->unsignedInteger('id_curso');
            $table->unsignedInteger('id_periodo_academico');

            $table->string('numero_seccion', 10);
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // 2. Definición del Unique Compuesto (Nuestra Reingeniería)
            $table->unique(
                ['id_carrera_jornada', 'id_curso', 'id_periodo_academico', 'numero_seccion'],
                'uq_seccion_jornada'
            );

            // 3. Índices de rendimiento
            $table->index('id_periodo_academico', 'idx_seccion_periodo');
            $table->index('id_carrera_jornada',   'idx_seccion_cj');

            // 4. Creación manual de las Llaves Foráneas (Asegura compatibilidad de tipos)
            $table->foreign('id_carrera_jornada', 'fk_seccion_carrera_jornada')
                  ->references('id_carrera_jornada')->on('carrera_jornada')
                  ->restrictOnDelete();

            $table->foreign('id_curso', 'fk_seccion_curso')
                  ->references('id_curso')->on('curso')
                  ->restrictOnDelete();

            $table->foreign('id_periodo_academico', 'fk_seccion_periodo')
                  ->references('id_periodo_academico')->on('periodo_academico')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion');
    }
};