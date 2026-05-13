<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Facultad\StoreFacultadRequest;
use App\Http\Requests\Facultad\UpdateFacultadRequest;
use App\Models\Facultad;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FacultadController extends Controller
{
    /**
     * GET /api/facultades
     */
    public function index(Request $request): JsonResponse
    {
        $query = Facultad::withCount(['carreras', 'carrerasActivas'])
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->when($request->buscar, fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('nombre_facultad', 'like', "%{$request->buscar}%")
                   ->orWhere('codigo_facultad', 'like', "%{$request->buscar}%");
            }))
            ->orderBy('nombre_facultad');

        return response()->json($query->get());
    }

    /**
     * POST /api/facultades
     */
    public function store(StoreFacultadRequest $request): JsonResponse
    {
        $facultad = Facultad::create([
            'nombre_facultad' => $request->nombre_facultad,
            'codigo_facultad' => $request->codigo_facultad ? strtoupper($request->codigo_facultad) : null,
            'descripcion'     => $request->descripcion,
            'estado'          => 'activo',       // ENUM string
            'fecha_creacion'  => now(),
        ]);

        HistorialService::registrarCreacion($facultad, 'facultad');

        return response()->json($facultad, 201);
    }

    /**
     * GET /api/facultades/{facultad}
     */
    public function show(int $id): JsonResponse
    {
        $facultad = Facultad::with('carrerasActivas')->findOrFail($id);
        return response()->json($facultad);
    }

    /**
     * PUT /api/facultades/{facultad}
     */
    public function update(UpdateFacultadRequest $request, int $id): JsonResponse
    {
        $facultad = Facultad::findOrFail($id);

        $datos = $request->only(['nombre_facultad', 'descripcion', 'estado']);
        if ($request->has('codigo_facultad')) {
            $datos['codigo_facultad'] = strtoupper($request->codigo_facultad);
        }

        HistorialService::registrarActualizacion($facultad, 'facultad');
        $facultad->update($datos);

        return response()->json($facultad);
    }

    /**
     * DELETE /api/facultades/{facultad}
     * Soft delete si no tiene carreras activas.
     */
    public function destroy(int $id): JsonResponse
    {
        $facultad = Facultad::withCount('carrerasActivas')->findOrFail($id);

        if ($facultad->carreras_activas_count > 0) {
            return response()->json([
                'message' => 'No se puede desactivar una facultad con carreras activas.',
            ], 422);
        }

        HistorialService::registrarEliminacion($facultad, 'facultad');
        // SQL oficial: ENUM — usar string 'inactivo'
        $facultad->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Facultad desactivada correctamente.']);
    }
}
