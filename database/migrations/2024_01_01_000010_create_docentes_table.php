<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docente', function (Blueprint $table) {
            // SQL oficial: int(10) UNSIGNED AUTO_INCREMENT
            $table->unsignedInteger('id_docente')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED FK UNIQUE
            $table->unsignedInteger('id_usuario')->unique();
            // SQL oficial: varchar(20) DEFAULT NULL (nullable, unique)
            $table->string('codigo_docente', 20)->nullable()->unique();
            // SQL oficial: int(11) NOT NULL DEFAULT 3
            // Validación 1|2|3 se aplica en la capa de aplicación y con CHECK
            $table->integer('prioridad')->default(3);
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
            $table->dateTime('fecha_creacion')->useCurrent();
            $table->dateTime('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->foreign('id_usuario', 'fk_docente_usuario')
                ->references('id_usuario')->on('usuario')
                ->restrictOnDelete();

            $table->index('prioridad');
        });

        // CHECK constraint para MySQL 8.0.16+ / MariaDB 10.4+
        // Garantiza que prioridad solo acepte 1, 2 o 3 a nivel de BD
        DB::statement('ALTER TABLE docente ADD CONSTRAINT chk_docente_prioridad CHECK (prioridad IN (1, 2, 3))');
    }

    public function down(): void
    {
        Schema::dropIfExists('docente');
    }
};
