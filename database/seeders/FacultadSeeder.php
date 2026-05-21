<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * FacultadSeeder
 *
 * Inserta las facultades iniciales de la UMG asignadas a sus sedes.
 * Depende de: CentroEducativoSeeder (id_centro_educativo debe existir).
 */
class FacultadSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('facultad')->insert([
            // ── Sede Central ──────────────────────────────
            [
                'id_centro_educativo' => 1,
                'nombre_facultad'     => 'Facultad de Ciencias de la Administración',
                'codigo_facultad'     => 'FCA',
                'descripcion'         => 'Administración de Empresas, Contaduría y Auditoría.',
                'estado'              => 'activo',
            ],
            [
                'id_centro_educativo' => 1,
                'nombre_facultad'     => 'Facultad de Ciencias Jurídicas y Sociales',
                'codigo_facultad'     => 'FCJS',
                'descripcion'         => 'Ciencias Jurídicas y Sociales.',
                'estado'              => 'activo',
            ],
            [
                'id_centro_educativo' => 1,
                'nombre_facultad'     => 'Facultad de Ciencias de la Salud',
                'codigo_facultad'     => 'FCS',
                'descripcion'         => 'Ciencias de la Salud y Medicina.',
                'estado'              => 'activo',
            ],
            // ── Sede Antigua Guatemala ────────────────────
            [
                'id_centro_educativo' => 2,
                'nombre_facultad'     => 'Facultad de Ingeniería - Antigua',
                'codigo_facultad'     => 'FI-ANT',
                'descripcion'         => 'Ingeniería en Sistemas e Industrial, Sede Antigua.',
                'estado'              => 'activo',
            ],
            // ── Sede Villa Nueva ──────────────────────────
            [
                'id_centro_educativo' => 3,
                'nombre_facultad'     => 'Facultad de Humanidades - Villa Nueva',
                'codigo_facultad'     => 'FH-VN',
                'descripcion'         => 'Pedagogía y Humanidades, Sede Villa Nueva.',
                'estado'              => 'activo',
            ],
        ]);
    }
}
