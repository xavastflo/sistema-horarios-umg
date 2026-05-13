<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facultad', function (Blueprint $table) {
            // SQL oficial: smallint(5) UNSIGNED AUTO_INCREMENT
            $table->unsignedSmallInteger('id_facultad')->autoIncrement();
            // SQL oficial: varchar(100)
            $table->string('nombre_facultad', 100)->unique();
            // SQL oficial: varchar(20) DEFAULT NULL (nullable en el SQL oficial)
            $table->string('codigo_facultad', 20)->unique()->nullable();
            // SQL oficial: varchar(200)
            $table->string('descripcion', 200)->nullable();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facultad');
    }
};
