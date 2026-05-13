<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seccion', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_seccion')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_curso');
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_periodo_academico');
            // SQL oficial: varchar(10)
            $table->string('numero_seccion', 10);
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // SQL oficial: UNIQUE(id_curso, id_periodo_academico, numero_seccion)
            $table->unique(
                ['id_curso', 'id_periodo_academico', 'numero_seccion'],
                'uq_seccion'
            );
            $table->index('id_periodo_academico', 'fk_seccion_periodo');

            $table->foreign('id_curso', 'fk_seccion_curso')
                ->references('id_curso')->on('curso')
                ->restrictOnDelete();

            $table->foreign('id_periodo_academico', 'fk_seccion_periodo_fk')
                ->references('id_periodo_academico')->on('periodo_academico')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seccion');
    }
};
