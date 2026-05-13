<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Orden importante: los seeders con dependencias van después.
     */
    public function run(): void
    {
        $this->call([
            RolSeeder::class,          // Sin dependencias
            JornadaSeeder::class,      // Sin dependencias
            DiaSeeder::class,          // Sin dependencias
            EstadoHorarioSeeder::class,// Sin dependencias
            AdminSeeder::class,        // Depende de RolSeeder
        ]);
    }
}
