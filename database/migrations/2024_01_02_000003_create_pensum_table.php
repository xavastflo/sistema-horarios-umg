<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pensum', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_pensum')->autoIncrement();

            // Carrera a la que pertenece el pensum
            $table->unsignedInteger('id_carrera');

            // Vigencia académica del pensum
            $table->unsignedSmallInteger('anio_inicio_vigencia');
            $table->unsignedSmallInteger('anio_fin_vigencia')->nullable();

            // Datos descriptivos del pensum
            $table->string('nombre_pensum', 120);
            $table->string('codigo_pensum', 20)->unique();
            $table->string('descripcion', 200)->nullable();

            // Estado
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');

            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_carrera', 'fk_pensum_carrera')
                ->references('id_carrera')->on('carrera')
                ->restrictOnDelete();

            $table->index('id_carrera', 'idx_pensum_carrera');
            $table->index(['id_carrera', 'estado'], 'idx_pensum_carrera_estado');
            $table->index(['anio_inicio_vigencia', 'anio_fin_vigencia'], 'idx_pensum_vigencia');

            // Evita dos pensums con el mismo año de inicio para una misma carrera.
            // Los solapamientos de rangos más complejos se validan en los Requests.
            $table->unique(['id_carrera', 'anio_inicio_vigencia'], 'uq_pensum_carrera_inicio');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pensum');
    }
};
