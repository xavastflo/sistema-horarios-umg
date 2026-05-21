<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla centro_educativo (Sedes de la UMG).
 * Número de archivo: 2024_01_01_000004b — se ejecuta ANTES de facultad (000005).
 *
 * Jerarquía:
 *   CentroEducativo → Facultad → Carrera → CarreraJornada → ...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('centro_educativo', function (Blueprint $table) {
            $table->unsignedSmallInteger('id_centro_educativo')->autoIncrement();
            $table->string('nombre', 150);
            $table->string('codigo_sede', 20)->unique()->nullable();
            $table->string('direccion', 255)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('centro_educativo');
    }
};
