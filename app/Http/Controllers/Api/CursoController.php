<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Curso\StoreCursoRequest;
use App\Http\Requests\Curso\UpdateCursoRequest;
use App\Models\Curso;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CursoController extends Controller
{
    /**
     * GET /api/cursos
     */
    public function index(Request $request): JsonResponse
    {
        $query = Curso::query()
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->when($request->buscar, fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('nombre_curso', 'like', "%{$request->buscar}%")
                   ->orWhere('codigo_curso', 'like', "%{$request->buscar}%");
            }))
            ->orderBy('nombre_curso');

        return response()->json($query->get());
    }

    /**
     * POST /api/cursos
     */
    public function store(StoreCursoRequest $request): JsonResponse
    {
        $curso = Curso::create([
            'codigo_curso'        => strtoupper($request->codigo_curso),
            'nombre_curso'        => $request->nombre_curso,
            'estado'              => 'activo',
            'fecha_creacion'      => now(),
            'fecha_actualizacion' => now(),
        ]);

        HistorialService::registrarCreacion($curso, 'curso');

        return response()->json($curso, 201);
    }

    /**
     * GET /api/cursos/{curso}
     */
    public function show(int $id): JsonResponse
    {
        $curso = Curso::findOrFail($id);
        return response()->json($curso);
    }

    /**
     * PUT /api/cursos/{curso}
     */
    public function update(UpdateCursoRequest $request, int $id): JsonResponse
    {
        $curso = Curso::findOrFail($id);

        $datos = $request->only(['nombre_curso', 'estado']);
        if ($request->has('codigo_curso')) {
            $datos['codigo_curso'] = strtoupper($request->codigo_curso);
        }

        HistorialService::registrarActualizacion($curso, 'curso');
        $curso->update($datos);

        return response()->json($curso);
    }

    /**
     * DELETE /api/cursos/{curso}
     * Soft delete — no eliminar si tiene secciones activas.
     */
    public function destroy(int $id): JsonResponse
    {
        $curso = Curso::withCount(['secciones' => fn($q) => $q->where('estado', 'activo')])->findOrFail($id);

        if ($curso->secciones_count > 0) {
            return response()->json([
                'message' => 'No se puede desactivar un curso con secciones activas.',
            ], 422);
        }

        HistorialService::registrarEliminacion($curso, 'curso');
        $curso->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Curso desactivado correctamente.']);
    }
}
