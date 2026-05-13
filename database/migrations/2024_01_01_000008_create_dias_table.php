<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dia', function (Blueprint $table) {
            // SQL oficial: tinyint(3) UNSIGNED (no auto_increment — IDs fijos 1-7)
            $table->unsignedTinyInteger('id_dia')->primary();
            // SQL oficial: varchar(15)
            $table->string('nombre_dia', 15)->unique();
            // SQL oficial: tinyint(3) UNSIGNED UNIQUE
            $table->unsignedTinyInteger('orden_semana')->unique();
            // SQL oficial: ENUM('activo','inactivo')
            $table->enum('estado', ['activo', 'inactivo'])->default('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dia');
    }
};
