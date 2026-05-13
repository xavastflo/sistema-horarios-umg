<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_rol', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED
            $table->unsignedInteger('id_usuario_rol')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK
            $table->unsignedInteger('id_usuario');
            // SQL oficial: tinyint(3) UNSIGNED FK
            $table->unsignedTinyInteger('id_rol');
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_asignacion')->useCurrent();
            $table->dateTime('fecha_desasignacion')->nullable();

            $table->foreign('id_usuario')
                ->references('id_usuario')->on('usuario')
                ->cascadeOnDelete();
            $table->foreign('id_rol')
                ->references('id_rol')->on('rol')
                ->cascadeOnDelete();

            $table->unique(['id_usuario', 'id_rol'], 'uq_usuario_rol');
            $table->index('id_rol', 'fk_usuario_rol_rol');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_rol');
    }
};
