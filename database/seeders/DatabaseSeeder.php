<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Orden estricto por dependencias de FK:
     *   1. Sin FK            → Rol, Jornada, Dia, EstadoHorario
     *   2. Depende de Rol    → Admin
     *   3. Sin FK (nueva)    → CentroEducativo
     *   4. Depende de Centro → Facultad
     */
    public function run(): void
    {
        $this->call([
            RolSeeder::class,             // Sin dependencias
            JornadaSeeder::class,         // Sin dependencias
            DiaSeeder::class,             // Sin dependencias
            EstadoHorarioSeeder::class,   // Sin dependencias
            AdminSeeder::class,           // Depende de RolSeeder
            CentroEducativoSeeder::class, // Sin dependencias (nueva tabla)
            FacultadSeeder::class,        // Depende de CentroEducativoSeeder
        ]);
    }
}
