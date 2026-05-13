<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class JornadaSeeder extends Seeder
{
    public function run(): void
    {
        // SQL oficial: id_jornada tinyint, nombre varchar(50), estado ENUM
        $jornadas = [
            ['id_jornada' => 1, 'nombre_jornada' => 'matutina',      'descripcion' => 'Jornada matutina'],
            ['id_jornada' => 2, 'nombre_jornada' => 'vespertina',     'descripcion' => 'Jornada vespertina'],
            ['id_jornada' => 3, 'nombre_jornada' => 'fin_de_semana',  'descripcion' => 'Plan fin de semana'],
        ];

        foreach ($jornadas as $jornada) {
            DB::table('jornada')->updateOrInsert(
                ['id_jornada' => $jornada['id_jornada']],
                array_merge($jornada, [
                    'estado'         => 'activo',   // ENUM string
                    'fecha_creacion' => now(),
                ])
            );
        }
    }
}
