<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HistorialCambios;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistorialController extends Controller
{
    /**
     * GET /api/historial
     * Lista historial con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $query = HistorialCambios::with('usuario')
            ->when($request->tabla, fn($q) => $q->where('tabla_afectada', $request->tabla))
            ->when($request->id_registro, fn($q) => $q->where('id_registro_afectado', $request->id_registro))
            ->when($request->tipo_cambio, fn($q) => $q->where('tipo_cambio', $request->tipo_cambio))
            ->when($request->id_usuario, fn($q) => $q->where('id_usuario', $request->id_usuario))
            ->when($request->fecha_desde, fn($q) => $q->where('fecha_cambio', '>=', $request->fecha_desde))
            ->when($request->fecha_hasta, fn($q) => $q->where('fecha_cambio', '<=', $request->fecha_hasta))
            ->orderByDesc('fecha_cambio');

        $resultado = $request->per_page
            ? $query->paginate($request->per_page)
            : $query->limit(200)->get();

        return response()->json($resultado);
    }

    /**
     * GET /api/historial/{tabla}/{id}
     * Historial de un registro específico.
     */
    public function porRegistro(string $tabla, int $id): JsonResponse
    {
        $historial = HistorialCambios::with('usuario')
            ->porRegistro($tabla, $id)
            ->orderByDesc('fecha_cambio')
            ->get();

        return response()->json($historial);
    }
}
