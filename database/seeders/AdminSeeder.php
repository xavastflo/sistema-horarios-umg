<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        // ── 1. Obtener rol administrador ────────────────────
        $rolAdmin = DB::table('rol')
            ->where('nombre_rol', 'administrador')
            ->first();

        if (! $rolAdmin) {
            $this->command->error('El rol administrador no existe. Ejecute RolSeeder primero.');
            return;
        }

        // ── 2. Crear usuario administrador ──────────────────
        $existe = DB::table('usuario')
            ->where('nombre_usuario', 'admin')
            ->first();

        if ($existe) {
            $this->command->info('El usuario admin ya existe. Se omite creación.');
            $idUsuario = $existe->id_usuario;
        } else {
            $idUsuario = DB::table('usuario')->insertGetId([
                'nombres'                  => 'Administrador',
                'apellidos'                => 'Sistema',
                'nombre_usuario'           => 'admin',
                'correo_electronico'       => 'admin@universidad.edu',
                'telefono'                 => null,
                'password_hash'            => Hash::make('Admin@2024!'),
                // SQL oficial: NOT NULL — no puede ser null
                'pregunta_seguridad'       => '¿Cuál es el nombre del sistema?',
                'respuesta_seguridad_hash' => Hash::make('horarios'),
                // SQL oficial: varchar(100) — guarda el nombre del rol como texto
                'ultimo_perfil_activo'     => 'administrador',
                // SQL oficial: ENUM('activo','inactivo','bloqueado')
                'estado'                   => 'activo',
                'fecha_creacion'           => now(),
                'fecha_actualizacion'      => now(),
            ]);

            $this->command->info("Usuario admin creado con ID: {$idUsuario}");
        }

        // ── 3. Asignar rol administrador si no lo tiene ─────
        $tieneRol = DB::table('usuario_rol')
            ->where('id_usuario', $idUsuario)
            ->where('id_rol', $rolAdmin->id_rol)
            ->first();

        if (! $tieneRol) {
            DB::table('usuario_rol')->insert([
                'id_usuario'       => $idUsuario,
                'id_rol'           => $rolAdmin->id_rol,
                'estado'           => 'activo',   // ENUM string
                'fecha_asignacion' => now(),
            ]);
            $this->command->info('Rol administrador asignado.');
        } else {
            // Reactivar si estaba inactivo
            DB::table('usuario_rol')
                ->where('id_usuario', $idUsuario)
                ->where('id_rol', $rolAdmin->id_rol)
                ->update(['estado' => 'activo']);
            $this->command->info('El usuario ya tenía el rol administrador.');
        }

        $this->command->info('');
        $this->command->info('╔══════════════════════════════════════╗');
        $this->command->info('║     CREDENCIALES INICIALES ADMIN     ║');
        $this->command->info('╠══════════════════════════════════════╣');
        $this->command->info('║  Usuario : admin                     ║');
        $this->command->info('║  Password: Admin@2024!               ║');
        $this->command->info('║  Correo  : admin@universidad.edu     ║');
        $this->command->info('╚══════════════════════════════════════╝');
        $this->command->warn('  ⚠ Cambie la contraseña en producción.');
    }
}
