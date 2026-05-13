<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horario', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_horario')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK → carrera
            $table->unsignedInteger('id_carrera');
            // SQL oficial: int(10) UNSIGNED FK → periodo_academico
            $table->unsignedInteger('id_periodo_academico');
            // SQL oficial: tinyint(3) UNSIGNED FK → estado_horario
            $table->unsignedTinyInteger('id_estado_horario');
            // SQL oficial: tinyint(3) UNSIGNED DEFAULT 1
            $table->unsignedTinyInteger('version_horario')->default(1);
            // SQL oficial: datetime NOT NULL DEFAULT current_timestamp()
            $table->dateTime('fecha_generacion')->useCurrent();
            // SQL oficial: datetime NULL
            $table->dateTime('fecha_aprobacion')->nullable();
            $table->dateTime('fecha_bloqueo')->nullable();
            // SQL oficial: varchar(200) NULL
            $table->string('observaciones', 200)->nullable();
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // SQL oficial: UNIQUE(id_carrera, id_periodo_academico, version_horario)
            $table->unique(
                ['id_carrera', 'id_periodo_academico', 'version_horario'],
                'uq_horario_version'
            );

            // SQL oficial: KEY fk_horario_periodo, KEY fk_horario_estado
            $table->index('id_periodo_academico', 'fk_horario_periodo');
            $table->index('id_estado_horario',    'fk_horario_estado');

            $table->foreign('id_carrera', 'fk_horario_carrera')
                ->references('id_carrera')->on('carrera')
                ->restrictOnDelete();

            $table->foreign('id_periodo_academico', 'fk_horario_periodo_fk')
                ->references('id_periodo_academico')->on('periodo_academico')
                ->restrictOnDelete();

            $table->foreign('id_estado_horario', 'fk_horario_estado_fk')
                ->references('id_estado_horario')->on('estado_horario')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horario');
    }
};
