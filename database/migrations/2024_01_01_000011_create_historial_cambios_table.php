<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historial_cambios', function (Blueprint $table) {
            // SQL oficial: bigint(20) UNSIGNED AUTO_INCREMENT
            $table->unsignedBigInteger('id_historial_cambios')->autoIncrement();
            // SQL oficial: int(10) UNSIGNED NOT NULL (no nullable)
            $table->unsignedInteger('id_usuario');
            // SQL oficial: varchar(100)
            $table->string('tabla_afectada', 100);
            // SQL oficial: bigint(20) UNSIGNED
            $table->unsignedBigInteger('id_registro_afectado');
            $table->enum('tipo_cambio', [
                'insert',
                'update',
                'delete',
                'aprobacion',
                'bloqueo',
                'duplicacion',
                'asignacion',
            ]);
            // SQL oficial: text DEFAULT NULL
            $table->text('valor_anterior')->nullable();
            $table->text('valor_nuevo')->nullable();
            // SQL oficial: varchar(255)
            $table->string('motivo_cambio', 255)->nullable();
            $table->dateTime('fecha_cambio')->useCurrent();

            $table->foreign('id_usuario', 'fk_historial_usuario')
                ->references('id_usuario')->on('usuario')
                ->restrictOnDelete();

            $table->index(['tabla_afectada', 'id_registro_afectado'], 'idx_hc_tabla_registro');
            $table->index('id_usuario', 'idx_hc_usuario');
            $table->index('fecha_cambio', 'idx_hc_fecha');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historial_cambios');
    }
};
