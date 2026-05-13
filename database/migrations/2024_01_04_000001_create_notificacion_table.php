<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notificacion', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY
            // El DDL original declara sin AUTO_INCREMENT y lo agrega en ALTER TABLE.
            // increments() es equivalente: unsignedInt + autoIncrement + primaryKey.
            $table->increments('id_notificacion');

            // SQL oficial: int(10) UNSIGNED NOT NULL FK → usuario
            $table->unsignedInteger('id_usuario');

            // SQL oficial: varchar(100) NOT NULL
            $table->string('titulo', 100);

            // SQL oficial: varchar(255) NOT NULL
            $table->string('mensaje', 255);

            // SQL oficial: ENUM 4 valores, DEFAULT 'general'
            $table->enum('tipo_notificacion', [
                'cambio_horario',
                'bloqueo_horario',
                'aprobacion_horario',
                'general',
            ])->default('general');

            // SQL oficial: tinyint(1) NOT NULL DEFAULT 0
            $table->boolean('leida')->default(false);

            // SQL oficial: datetime NOT NULL DEFAULT current_timestamp()
            $table->dateTime('fecha_envio')->useCurrent();

            // SQL oficial: datetime DEFAULT NULL
            $table->dateTime('fecha_lectura')->nullable();

            // SQL oficial: ENUM('activo','inactivo') DEFAULT 'activo'
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');

            // SQL oficial: KEY fk_notificacion_usuario (id_usuario)
            $table->index('id_usuario', 'fk_notificacion_usuario');

            $table->foreign('id_usuario', 'fk_notificacion_usuario_fk')
                ->references('id_usuario')->on('usuario')
                ->restrictOnDelete();   // SQL oficial: sin ON DELETE CASCADE
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notificacion');
    }
};
