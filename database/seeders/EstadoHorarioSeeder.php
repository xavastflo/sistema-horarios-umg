<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class EstadoHorarioSeeder extends Seeder
{
    public function run(): void
    {
        // SQL oficial: id_estado_horario tinyint AUTO_INCREMENT con IDs 1-5
        $estados = [
            ['id_estado_horario' => 1, 'nombre_estado' => 'borrador',  'descripcion' => 'Horario en creación o edición'],
            ['id_estado_horario' => 2, 'nombre_estado' => 'generado',  'descripcion' => 'Horario generado por el sistema'],
            ['id_estado_horario' => 3, 'nombre_estado' => 'aprobado',  'descripcion' => 'Horario aprobado por administración'],
            ['id_estado_horario' => 4, 'nombre_estado' => 'bloqueado', 'descripcion' => 'Horario bloqueado, no editable'],
            ['id_estado_horario' => 5, 'nombre_estado' => 'publicado', 'descripcion' => 'Horario visible para usuarios'],
        ];

        foreach ($estados as $estado) {
            DB::table('estado_horario')->updateOrInsert(
                ['id_estado_horario' => $estado['id_estado_horario']],
                array_merge($estado, ['estado' => 'activo'])  // ENUM string
            );
        }
    }
}
