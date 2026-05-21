<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CentroEducativoSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('centro_educativo')->insert([
            [
                'id_centro_educativo' => 1,
                'nombre'              => 'Sede Central - Campus Central',
                'codigo_sede'         => 'CENTRAL',
                'direccion'           => '6ª. Av. 7-63, zona 10, Ciudad de Guatemala',
                'estado'              => 'activo',
            ],
            [
                'id_centro_educativo' => 2,
                'nombre'              => 'Sede Antigua Guatemala',
                'codigo_sede'         => 'ANTIGUA',
                'direccion'           => '2ª. Calle 4-57, Antigua Guatemala, Sacatepéquez',
                'estado'              => 'activo',
            ],
            [
                'id_centro_educativo' => 3,
                'nombre'              => 'Sede Villa Nueva',
                'codigo_sede'         => 'VNUEVA',
                'direccion'           => 'Calzada Atanasio Tzul 22-00, zona 12, Villa Nueva',
                'estado'              => 'activo',
            ],
        ]);
    }
}
