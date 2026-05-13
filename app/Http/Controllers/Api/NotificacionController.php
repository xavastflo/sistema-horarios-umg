<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * NotificacionController — Sprint 4, Paso 2
 *
 * Rutas (todas bajo auth:sanctum, sin restricción adicional de rol):
 *   GET    /api/notificaciones                     → index()
 *   GET    /api/notificaciones/no-leidas           → noLeidas()
 *   PATCH  /api/notificaciones/leer-todas          → leerTodas()
 *   PATCH  /api/notificaciones/{id}/leer           → leer()
 *   DELETE /api/notificaciones/{id}                → destroy()
 *
 * Regla de acceso: cada usuario solo puede ver y modificar sus propias
 * notificaciones. Si la notificación no pertenece al autenticado → 403.
 */
class NotificacionController extends Controller
{
    /**
     * GET /api/notificaciones
     * Lista las notificaciones activas del usuario autenticado,
     * ordenadas por más reciente primero.
     */
    public function index(Request $request): JsonResponse
    {
        $notificaciones = Notificacion::delUsuario($request->user()->id_usuario)
            ->activas()
            ->orderByDesc('fecha_envio')
            ->get([
                'id_notificacion',
                'titulo',
                'mensaje',
                'tipo_notificacion',
                'leida',
                'fecha_envio',
                'fecha_lectura',
            ]);

        return response()->json([
            'total'          => $notificaciones->count(),
            'no_leidas'      => $notificaciones->where('leida', false)->count(),
            'notificaciones' => $notificaciones,
        ]);
    }

    /**
     * GET /api/notificaciones/no-leidas
     * Lista solo notificaciones activas y no leídas del usuario autenticado.
     */
    public function noLeidas(Request $request): JsonResponse
    {
        $notificaciones = Notificacion::delUsuario($request->user()->id_usuario)
            ->noLeidas()
            ->orderByDesc('fecha_envio')
            ->get([
                'id_notificacion',
                'titulo',
                'mensaje',
                'tipo_notificacion',
                'fecha_envio',
            ]);

        return response()->json([
            'total'          => $notificaciones->count(),
            'notificaciones' => $notificaciones,
        ]);
    }

    /**
     * PATCH /api/notificaciones/leer-todas
     * Marca todas las notificaciones activas no leídas del usuario autenticado
     * como leídas. No afecta las de otros usuarios.
     */
    public function leerTodas(Request $request): JsonResponse
    {
        $actualizadas = Notificacion::delUsuario($request->user()->id_usuario)
            ->noLeidas()
            ->update([
                'leida'         => true,
                'fecha_lectura' => now(),
            ]);

        return response()->json([
            'message'     => 'Notificaciones marcadas como leídas.',
            'actualizadas'=> $actualizadas,
        ]);
    }

    /**
     * PATCH /api/notificaciones/{id}/leer
     * Marca una notificación específica como leída.
     * Devuelve 403 si la notificación no pertenece al usuario autenticado.
     */
    public function leer(Request $request, int $idNotificacion): JsonResponse
    {
        $notificacion = Notificacion::where('id_notificacion', $idNotificacion)
            ->where('estado', 'activo')
            ->first();

        if (! $notificacion) {
            return response()->json(['message' => 'Notificación no encontrada.'], 404);
        }

        if ($notificacion->id_usuario !== $request->user()->id_usuario) {
            return response()->json(['message' => 'No tiene permisos sobre esta notificación.'], 403);
        }

        if ($notificacion->leida) {
            return response()->json(['message' => 'La notificación ya estaba marcada como leída.']);
        }

        $notificacion->update([
            'leida'         => true,
            'fecha_lectura' => now(),
        ]);

        return response()->json([
            'message'      => 'Notificación marcada como leída.',
            'notificacion' => [
                'id_notificacion' => $notificacion->id_notificacion,
                'leida'           => true,
                'fecha_lectura'   => $notificacion->fecha_lectura->toDateTimeString(),
            ],
        ]);
    }

    /**
     * DELETE /api/notificaciones/{id}
     * Eliminación lógica: cambia estado a 'inactivo'.
     * Devuelve 403 si la notificación no pertenece al usuario autenticado.
     */
    public function destroy(Request $request, int $idNotificacion): JsonResponse
    {
        $notificacion = Notificacion::where('id_notificacion', $idNotificacion)
            ->where('estado', 'activo')
            ->first();

        if (! $notificacion) {
            return response()->json(['message' => 'Notificación no encontrada.'], 404);
        }

        if ($notificacion->id_usuario !== $request->user()->id_usuario) {
            return response()->json(['message' => 'No tiene permisos sobre esta notificación.'], 403);
        }

        $notificacion->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Notificación eliminada correctamente.']);
    }
}
