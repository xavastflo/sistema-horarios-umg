<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Usuario\StoreUsuarioRequest;
use App\Http\Requests\Usuario\UpdateUsuarioRequest;
use App\Models\Rol;
use App\Models\Usuario;
use App\Models\UsuarioRol;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    /**
     * GET /api/usuarios
     * Lista usuarios con filtros opcionales.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Usuario::with('rolesActivos')
            // SQL oficial: estado es ENUM string
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->when($request->buscar, fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('nombres', 'like', "%{$request->buscar}%")
                   ->orWhere('apellidos', 'like', "%{$request->buscar}%")
                   ->orWhere('nombre_usuario', 'like', "%{$request->buscar}%")
                   ->orWhere('correo_electronico', 'like', "%{$request->buscar}%");
            }))
            ->when($request->id_rol, fn($q) => $q->whereHas(
                'rolesActivos', fn($q2) => $q2->where('rol.id_rol', $request->id_rol)
            ))
            // Excluir usuarios que ya tienen perfil docente (activo o inactivo).
            // id_usuario es UNIQUE en docente — no puede registrarse dos veces.
            ->when($request->sin_docente, fn($q) => $q->whereDoesntHave('docente'))
            ->orderBy('apellidos')
            ->orderBy('nombres');

        $usuarios = $request->per_page
            ? $query->paginate($request->per_page)
            : $query->get();

        return response()->json($usuarios);
    }

    /**
     * POST /api/usuarios
     */
    public function store(StoreUsuarioRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $usuario = Usuario::create([
                'nombres'                  => $request->nombres,
                'apellidos'                => $request->apellidos,
                'nombre_usuario'           => $request->nombre_usuario,
                'correo_electronico'       => $request->correo_electronico,
                'telefono'                 => $request->telefono,
                'password_hash'            => Hash::make($request->password),
                // SQL oficial: NOT NULL
                'pregunta_seguridad'       => $request->pregunta_seguridad,
                'respuesta_seguridad_hash' => Hash::make($request->respuesta_seguridad),
                // SQL oficial: varchar(100) — nulo al crear, se establece al asignar primer rol
                'ultimo_perfil_activo'     => null,
                // SQL oficial: ENUM — valor por defecto 'activo'
                'estado'                   => 'activo',
                'fecha_creacion'           => now(),
                'fecha_actualizacion'      => now(),
            ]);

            HistorialService::registrarCreacion($usuario, 'usuario');

            DB::commit();
            return response()->json($usuario->load('rolesActivos'), 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Error al crear usuario.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/usuarios/{usuario}
     */
    public function show(int $id): JsonResponse
    {
        $usuario = Usuario::with(['rolesActivos', 'docente'])->findOrFail($id);
        return response()->json($usuario);
    }

    /**
     * PUT /api/usuarios/{usuario}
     */
    public function update(UpdateUsuarioRequest $request, int $id): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);

        $datos = $request->only(['nombres', 'apellidos', 'nombre_usuario', 'correo_electronico', 'telefono', 'estado']);

        if ($request->has('pregunta_seguridad')) {
            $datos['pregunta_seguridad'] = $request->pregunta_seguridad;
        }
        if ($request->has('respuesta_seguridad') && $request->respuesta_seguridad) {
            $datos['respuesta_seguridad_hash'] = Hash::make($request->respuesta_seguridad);
        }

        HistorialService::registrarActualizacion($usuario, 'usuario');
        $usuario->update($datos);

        return response()->json($usuario->load('rolesActivos'));
    }

    /**
     * DELETE /api/usuarios/{usuario}
     * Soft delete: cambia estado a 'inactivo' (ENUM).
     */
    public function destroy(int $id): JsonResponse
    {
        $usuario = Usuario::findOrFail($id);

        HistorialService::registrarEliminacion($usuario, 'usuario');
        // SQL oficial: ENUM — usar string 'inactivo'
        $usuario->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Usuario desactivado correctamente.']);
    }

    // ── Gestión de roles ─────────────────────────────────────

    /**
     * POST /api/usuarios/{usuario}/roles
     * Asigna un rol a un usuario.
     */
    public function asignarRol(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_rol' => ['required', 'integer', 'exists:rol,id_rol'],
        ]);

        $usuario = Usuario::findOrFail($id);
        $rol = Rol::findOrFail($request->id_rol);

        $usuarioRol = UsuarioRol::where('id_usuario', $id)
            ->where('id_rol', $request->id_rol)
            ->first();

        if ($usuarioRol) {
            if ($usuarioRol->estado === 'activo') {
                return response()->json(['message' => 'El usuario ya tiene ese rol asignado.'], 422);
            }
            // Reactivar rol previamente desasignado
            $usuarioRol->update([
                'estado'              => 'activo',
                'fecha_asignacion'    => now(),
                'fecha_desasignacion' => null,
            ]);
        } else {
            $usuarioRol = UsuarioRol::create([
                'id_usuario'       => $id,
                'id_rol'           => $request->id_rol,
                'estado'           => 'activo',
                'fecha_asignacion' => now(),
            ]);
        }

        // Si no tiene perfil activo, establecer el nombre del rol como perfil
        // SQL oficial: varchar(100) — guardar nombre del rol, no ID
        if (! $usuario->ultimo_perfil_activo) {
            $usuario->update(['ultimo_perfil_activo' => $rol->nombre_rol]);
        }

        HistorialService::registrar(
            tabla:      'usuario_rol',
            idRegistro: $usuarioRol->id_usuario_rol,
            tipoCambio: 'asignacion',
            valorNuevo: ['id_usuario' => $id, 'id_rol' => $request->id_rol, 'nombre_rol' => $rol->nombre_rol],
            motivo:     'Asignación de rol',
        );

        return response()->json([
            'message' => "Rol '{$rol->nombre_rol}' asignado correctamente.",
            'usuario' => $usuario->load('rolesActivos'),
        ]);
    }

    /**
     * DELETE /api/usuarios/{usuario}/roles/{rol}
     * Quita un rol de un usuario.
     */
    public function quitarRol(int $idUsuario, int $idRol): JsonResponse
    {
        $usuarioRol = UsuarioRol::where('id_usuario', $idUsuario)
            ->where('id_rol', $idRol)
            ->where('estado', 'activo')
            ->first();

        if (! $usuarioRol) {
            return response()->json(['message' => 'El usuario no tiene ese rol asignado.'], 404);
        }

        HistorialService::registrar(
            tabla:         'usuario_rol',
            idRegistro:    $usuarioRol->id_usuario_rol,
            tipoCambio:    'delete',
            valorAnterior: $usuarioRol->toArray(),
            motivo:        'Desasignación de rol',
        );

        $usuarioRol->update([
            'estado'              => 'inactivo',
            'fecha_desasignacion' => now(),
        ]);

        $usuario = Usuario::with('rolesActivos')->findOrFail($idUsuario);

        // Si el perfil activo era el rol quitado, resetear al primer rol disponible
        $rolQuitado = Rol::find($idRol);
        if ($rolQuitado && $usuario->ultimo_perfil_activo === $rolQuitado->nombre_rol) {
            $primerRol = $usuario->rolesActivos->first();
            $usuario->update(['ultimo_perfil_activo' => $primerRol?->nombre_rol]);
        }

        return response()->json([
            'message' => 'Rol removido correctamente.',
            'usuario' => $usuario,
        ]);
    }
}
