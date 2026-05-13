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
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_carrera');
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_periodo_academico');
            // SQL oficial: varchar(120)
            $table->string('nombre_pensum', 120);
            // SQL oficial: varchar(20) UNIQUE
            $table->string('codigo_pensum', 20)->unique();
            // SQL oficial: varchar(200)
            $table->string('descripcion', 200)->nullable();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_carrera', 'fk_pensum_carrera')
                ->references('id_carrera')->on('carrera')
                ->restrictOnDelete();

            $table->foreign('id_periodo_academico', 'fk_pensum_periodo')
                ->references('id_periodo_academico')->on('periodo_academico')
                ->restrictOnDelete();

            $table->index('id_carrera', 'idx_pensum_carrera');
            $table->index('id_periodo_academico', 'idx_pensum_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pensum');
    }
};
