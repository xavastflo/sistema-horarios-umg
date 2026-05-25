<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RecuperarPasswordRequest;
use App\Models\Usuario;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     * Retorna token Sanctum + datos del usuario + roles activos.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $usuario = Usuario::where('nombre_usuario', $request->nombre_usuario)
            ->with('rolesActivos')
            ->first();

        // Verificar existencia y contraseña
        if (! $usuario || ! Hash::check($request->password, $usuario->password_hash)) {
            return response()->json([
                'message' => 'Credenciales incorrectas.',
            ], 401);
        }

        // SQL oficial: estado ENUM con valor 'bloqueado'
        if ($usuario->estado === 'bloqueado') {
            return response()->json([
                'message' => 'Su cuenta ha sido bloqueada. Contacte al administrador.',
            ], 403);
        }

        if ($usuario->estado === 'inactivo') {
            return response()->json([
                'message' => 'Su cuenta está inactiva.',
            ], 403);
        }

        // Revocar tokens anteriores (sesión única)
        $usuario->tokens()->delete();

        // Crear token con abilities según roles activos
        $abilities = $usuario->rolesActivos->pluck('nombre_rol')->toArray();
        $token = $usuario->createToken('auth_token', $abilities)->plainTextToken;

        // Actualizar último acceso
        $usuario->ultimo_acceso = now();
        $usuario->save();

        return response()->json([
            'token'      => $token,
            'tipo_token' => 'Bearer',
            'usuario'    => $this->formatUsuario($usuario),
        ]);
    }

    /**
     * POST /api/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente.']);
    }

    /**
     * GET /api/auth/me
     * Retorna datos del usuario autenticado.
     */
    public function me(Request $request): JsonResponse
    {
        $usuario = $request->user()->load('rolesActivos');

        return response()->json([
            'usuario' => $this->formatUsuario($usuario),
        ]);
    }

    /**
     * POST /api/auth/cambiar-perfil
     * Permite al usuario con múltiples roles cambiar su perfil activo.
     * ultimo_perfil_activo es varchar(100) — guarda el nombre del rol.
     */
    public function cambiarPerfil(Request $request): JsonResponse
    {
        $request->validate([
            'nombre_rol' => ['required', 'string', 'max:30'],
        ]);

        $usuario = $request->user();

        // Verificar que el rol pertenece al usuario (rol activo)
        $tieneRol = $usuario->rolesActivos()
            ->where('nombre_rol', $request->nombre_rol)
            ->exists();

        if (! $tieneRol) {
            return response()->json([
                'message' => 'No tiene asignado ese rol.',
            ], 403);
        }

        // SQL oficial: varchar(100) — guardar nombre del rol, no ID
        $usuario->ultimo_perfil_activo = $request->nombre_rol;
        $usuario->save();

        $usuario->load('rolesActivos');

        return response()->json([
            'message' => 'Perfil cambiado correctamente.',
            'usuario' => $this->formatUsuario($usuario),
        ]);
    }

    /**
     * GET /api/auth/pregunta-seguridad/{nombre_usuario}
     * Devuelve la pregunta de seguridad para el proceso de recuperación.
     *
     * Seguridad: el mensaje de error es genérico para evitar enumeración
     * de usuarios (user enumeration attack).
     */
    public function preguntaSeguridad(string $nombreUsuario): JsonResponse
    {
        $usuario = Usuario::where('nombre_usuario', $nombreUsuario)
            ->where('estado', 'activo')
            ->first();

        // Mensaje genérico: no revelar si el usuario existe o no
        if (! $usuario || ! $usuario->pregunta_seguridad) {
            return response()->json([
                'message' => 'No se pudo procesar la solicitud con los datos proporcionados.',
            ], 404);
        }

        return response()->json([
            'pregunta_seguridad' => $usuario->pregunta_seguridad,
        ]);
    }

    /**
     * POST /api/auth/recuperar-password
     * Valida respuesta de seguridad y cambia la contraseña.
     *
     * Seguridad:
     *   - Mensajes de error genéricos para evitar enumeración de usuarios.
     *   - Tokens revocados tras el cambio para invalidar sesiones previas.
     */
    public function recuperarPassword(RecuperarPasswordRequest $request): JsonResponse
    {
        $usuario = Usuario::where('nombre_usuario', $request->nombre_usuario)
            ->where('estado', 'activo')
            ->first();

        // Mensaje genérico: no revelar si el usuario existe o no
        if (! $usuario) {
            return response()->json([
                'message' => 'No se pudo procesar la solicitud con los datos proporcionados.',
            ], 404);
        }

        if (! $usuario->respuesta_seguridad_hash ||
            ! Hash::check($request->respuesta, $usuario->respuesta_seguridad_hash)) {
            return response()->json([
                'message' => 'No se pudo procesar la solicitud con los datos proporcionados.',
            ], 422);
        }

        $anterior = ['password_hash' => '[REDACTED]'];

        $usuario->password_hash = Hash::make($request->nueva_password);
        $usuario->save();

        // Revocar todos los tokens activos: invalida sesiones abiertas con la clave anterior
        $usuario->tokens()->delete();

        HistorialService::registrar(
            tabla:         'usuario',
            idRegistro:    $usuario->id_usuario,
            tipoCambio:    'update',
            valorAnterior: $anterior,
            valorNuevo:    ['password_hash' => '[REDACTED]'],
            motivo:        'Recuperación de contraseña por pregunta de seguridad',
            idUsuario:     $usuario->id_usuario,
        );

        return response()->json([
            'message' => 'Contraseña actualizada correctamente.',
        ]);
    }

    /**
     * POST /api/auth/cambiar-password
     *
     * Permite al usuario autenticado cambiar su propia contraseña.
     * Requiere la contraseña actual para confirmar identidad.
     *
     * Seguridad:
     *   - Regla 'current_password' valida contra getAuthPassword() → password_hash.
     *   - Tokens revocados tras el cambio: fuerza relogueo en todos los dispositivos.
     */
    public function cambiarPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password_actual' => ['required', 'current_password'],
            'password'        => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password_actual.required'         => 'La contraseña actual es obligatoria.',
            'password_actual.current_password' => 'La contraseña actual no es correcta.',
            'password.required'                => 'La nueva contraseña es obligatoria.',
            'password.min'                     => 'La nueva contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'               => 'La confirmación de la nueva contraseña no coincide.',
        ]);

        /** @var \App\Models\Usuario $usuario */
        $usuario = $request->user();

        // Columna real en BD es 'password_hash', no 'password'
        $usuario->password_hash = Hash::make($request->password);
        $usuario->save();

        // Revocar todos los tokens activos → fuerza relogueo seguro
        $usuario->tokens()->delete();

        return response()->json([
            'message' => 'Contraseña actualizada correctamente. Vuelve a iniciar sesión.',
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function formatUsuario(Usuario $usuario): array
    {
        return [
            'id_usuario'          => $usuario->id_usuario,
            'nombres'             => $usuario->nombres,
            'apellidos'           => $usuario->apellidos,
            'nombre_completo'     => $usuario->nombresCompletos(),
            'nombre_usuario'      => $usuario->nombre_usuario,
            'correo_electronico'  => $usuario->correo_electronico,
            'telefono'            => $usuario->telefono,
            // SQL oficial: ENUM('activo','inactivo','bloqueado')
            'estado'              => $usuario->estado,
            'ultimo_acceso'       => $usuario->ultimo_acceso,
            // SQL oficial: varchar(100) — nombre del rol como texto
            'perfil_activo'       => $usuario->ultimo_perfil_activo,
            'roles'               => $usuario->rolesActivos->map(fn($r) => [
                'id_rol'     => $r->id_rol,
                'nombre_rol' => $r->nombre_rol,
            ]),
        ];
    }
}
