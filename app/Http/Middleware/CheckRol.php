<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRol
{
    /**
     * Verifica que el usuario autenticado tenga al menos uno de los roles indicados.
     * La verificación usa rolesActivos() que ya filtra por estado='activo' (ENUM).
     *
     * Uso en rutas:
     *   ->middleware('rol:administrador')
     *   ->middleware('rol:administrador,coordinador')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $usuario = $request->user();

        if (! $usuario) {
            return response()->json([
                'message' => 'No autenticado.',
            ], 401);
        }

        // Estado bloqueado no puede operar aunque tenga token válido
        if ($usuario->estado === 'bloqueado') {
            return response()->json([
                'message' => 'Su cuenta ha sido bloqueada. Contacte al administrador.',
            ], 403);
        }

        // Verificar rol activo (ENUM 'activo' en usuario_rol)
        $tieneRol = $usuario->rolesActivos()
            ->whereIn('nombre_rol', $roles)
            ->exists();

        if (! $tieneRol) {
            return response()->json([
                'message'          => 'No tiene permisos para esta acción.',
                'roles_requeridos' => $roles,
            ], 403);
        }

        return $next($request);
    }
}
