<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolSeeder extends Seeder
{
    public function run(): void
    {
        // SQL oficial: id_rol tinyint, nombre varchar(30), estado ENUM
        $roles = [
            ['id_rol' => 1, 'nombre_rol' => 'administrador', 'descripcion' => 'Administrador del sistema'],
            ['id_rol' => 2, 'nombre_rol' => 'coordinador',   'descripcion' => 'Coordinador académico'],
            ['id_rol' => 3, 'nombre_rol' => 'docente',       'descripcion' => 'Docente del sistema'],
            ['id_rol' => 4, 'nombre_rol' => 'estudiante',    'descripcion' => 'Estudiante'],
        ];

        foreach ($roles as $rol) {
            DB::table('rol')->updateOrInsert(
                ['id_rol' => $rol['id_rol']],
                array_merge($rol, [
                    'estado'         => 'activo',   // ENUM string
                    'fecha_creacion' => now(),
                ])
            );
        }
    }
}
