<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DiaSeeder extends Seeder
{
    public function run(): void
    {
        // SQL oficial: id_dia tinyint PK fija, nombre varchar(15) minúsculas sin tilde,
        // estado ENUM('activo','inactivo')
        $dias = [
            ['id_dia' => 1, 'nombre_dia' => 'lunes',     'orden_semana' => 1],
            ['id_dia' => 2, 'nombre_dia' => 'martes',    'orden_semana' => 2],
            ['id_dia' => 3, 'nombre_dia' => 'miercoles', 'orden_semana' => 3],
            ['id_dia' => 4, 'nombre_dia' => 'jueves',    'orden_semana' => 4],
            ['id_dia' => 5, 'nombre_dia' => 'viernes',   'orden_semana' => 5],
            ['id_dia' => 6, 'nombre_dia' => 'sabado',    'orden_semana' => 6],
            ['id_dia' => 7, 'nombre_dia' => 'domingo',   'orden_semana' => 7],
        ];

        foreach ($dias as $dia) {
            DB::table('dia')->updateOrInsert(
                ['id_dia' => $dia['id_dia']],
                array_merge($dia, ['estado' => 'activo'])  // ENUM string
            );
        }
    }
}
