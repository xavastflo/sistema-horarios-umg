<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periodo_academico', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_periodo_academico')->autoIncrement();
            // SQL oficial: varchar(100)
            $table->string('nombre_periodo', 100);
            // SQL oficial: year(4)
            $table->year('anio');
            // SQL oficial: tinyint(3) UNSIGNED
            $table->unsignedTinyInteger('numero_periodo');
            // SQL oficial: date
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            // SQL oficial: datetime NULL
            $table->dateTime('fecha_limite_edicion_horarios')->nullable();
            // SQL oficial: ENUM de 4 valores propios — diferente al ENUM de otras tablas
            $table->enum('estado', ['planificacion', 'activo', 'cerrado', 'finalizado'])
                  ->default('planificacion');
            // SQL oficial: tinyint(1) DEFAULT 0 (boolean)
            $table->boolean('es_vigente')->default(false);
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            // SQL oficial: UNIQUE(anio, numero_periodo)
            $table->unique(['anio', 'numero_periodo'], 'uq_periodo_anio_numero');
        });
        // Sin ENGINE explícito en el SQL oficial para esta tabla (sin InnoDB declarado)
        // Laravel usa InnoDB por defecto — compatible
    }

    public function down(): void
    {
        Schema::dropIfExists('periodo_academico');
    }
};
