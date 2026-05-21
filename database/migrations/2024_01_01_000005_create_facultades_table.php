<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facultad', function (Blueprint $table) {
            $table->unsignedSmallInteger('id_facultad')->autoIncrement();

            // FK a sede — obligatoria, sin cascade (evita borrados accidentales)
            $table->unsignedSmallInteger('id_centro_educativo');
            $table->foreign('id_centro_educativo', 'fk_facultad_centro')
                  ->references('id_centro_educativo')
                  ->on('centro_educativo')
                  ->restrictOnDelete();   // QA: restrictOnDelete en lugar de cascade

            $table->string('nombre_facultad', 100);  // unique compuesto, no global
            $table->string('codigo_facultad', 20)->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // QA: unicidad compuesta por sede — permite mismo nombre en distintas sedes
            $table->unique(['id_centro_educativo', 'nombre_facultad'], 'uid_sede_nombre_facultad');
            $table->unique(['id_centro_educativo', 'codigo_facultad'], 'uid_sede_codigo_facultad');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facultad');
    }
};
