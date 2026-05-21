<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curso', function (Blueprint $table) {
            $table->unsignedInteger('id_curso')->autoIncrement();
            $table->string('codigo_curso', 20)->unique();
            $table->string('nombre_curso', 120);
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curso');
    }
};
