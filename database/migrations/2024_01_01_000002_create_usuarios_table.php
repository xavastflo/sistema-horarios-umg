<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_usuario')->autoIncrement();
            $table->string('nombres', 100);
            $table->string('apellidos', 100);
            $table->string('nombre_usuario', 50)->unique();
            $table->string('correo_electronico', 120)->unique();
            $table->string('telefono', 20)->nullable();
            $table->string('password_hash', 255);
            // SQL oficial: NOT NULL (sin nullable)
            $table->string('pregunta_seguridad', 150);
            $table->string('respuesta_seguridad_hash', 255);
            $table->dateTime('ultimo_acceso')->nullable();
            // SQL oficial: varchar(100) — guarda el nombre del rol como texto, no FK
            $table->string('ultimo_perfil_activo', 100)->nullable();
            // SQL oficial: ENUM('activo','inactivo','bloqueado')
            $table->enum('estado', ['activo', 'inactivo', 'bloqueado'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario');
    }
};
