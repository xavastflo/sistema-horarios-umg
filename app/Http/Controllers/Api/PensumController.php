<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pensum\StorePensumRequest;
use App\Http\Requests\Pensum\UpdatePensumRequest;
use App\Http\Requests\PensumCurso\StorePensumCursoRequest;
use App\Models\Pensum;
use App\Models\PensumCurso;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PensumController extends Controller
{
    /**
     * GET /api/pensums
     */
    public function index(Request $request): JsonResponse
    {
        $query = Pensum::with(['carrera', 'periodoAcademico'])
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->when($request->id_carrera, fn($q) => $q->where('id_carrera', $request->id_carrera))
            ->when($request->id_periodo_academico, fn($q) => $q->where('id_periodo_academico', $request->id_periodo_academico))
            ->when($request->buscar, fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('nombre_pensum', 'like', "%{$request->buscar}%")
                   ->orWhere('codigo_pensum', 'like', "%{$request->buscar}%");
            }))
            ->orderBy('nombre_pensum');

        return response()->json($query->get());
    }

    /**
     * POST /api/pensums
     */
    public function store(StorePensumRequest $request): JsonResponse
    {
        $pensum = Pensum::create([
            'id_carrera'           => $request->id_carrera,
            'id_periodo_academico' => $request->id_periodo_academico,
            'nombre_pensum'        => $request->nombre_pensum,
            'codigo_pensum'        => strtoupper($request->codigo_pensum),
            'descripcion'          => $request->descripcion,
            'estado'               => 'activo',
            'fecha_creacion'       => now(),
            'fecha_actualizacion'  => now(),
        ]);

        HistorialService::registrarCreacion($pensum, 'pensum');

        return response()->json($pensum->load(['carrera', 'periodoAcademico']), 201);
    }

    /**
     * GET /api/pensums/{pensum}
     */
    public function show(int $id): JsonResponse
    {
        $pensum = Pensum::with([
            'carrera',
            'periodoAcademico',
            'pensumCursos.curso',
        ])->findOrFail($id);

        return response()->json($pensum);
    }

    /**
     * PUT /api/pensums/{pensum}
     */
    public function update(UpdatePensumRequest $request, int $id): JsonResponse
    {
        $pensum = Pensum::findOrFail($id);
        $datos  = $request->only(['nombre_pensum', 'descripcion', 'estado']);

        if ($request->has('codigo_pensum')) {
            $datos['codigo_pensum'] = strtoupper($request->codigo_pensum);
        }

        HistorialService::registrarActualizacion($pensum, 'pensum');
        $pensum->update($datos);

        return response()->json($pensum->load(['carrera', 'periodoAcademico']));
    }

    /**
     * DELETE /api/pensums/{pensum}
     */
    public function destroy(int $id): JsonResponse
    {
        $pensum = Pensum::findOrFail($id);

        HistorialService::registrarEliminacion($pensum, 'pensum');
        $pensum->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Pensum desactivado correctamente.']);
    }

    // ── Gestión de cursos dentro del pensum ──────────────────

    /**
     * GET /api/pensums/{pensum}/cursos
     * Lista los cursos del pensum, agrupables por ciclo.
     */
    public function cursos(Request $request, int $id): JsonResponse
    {
        $pensum = Pensum::findOrFail($id);

        $query = PensumCurso::with('curso')
            ->where('id_pensum', $id)
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->when($request->ciclo_semestre, fn($q) => $q->where('ciclo_semestre', $request->ciclo_semestre))
            ->orderBy('ciclo_semestre')
            ->orderBy('id_pensum_curso');

        return response()->json($query->get());
    }

    /**
     * POST /api/pensums/{pensum}/cursos
     * Asocia un curso al pensum en un ciclo/semestre determinado.
     * REGLA: un curso no puede repetirse dentro del mismo pensum.
     */
    public function agregarCurso(StorePensumCursoRequest $request, int $id): JsonResponse
    {
        $pensum = Pensum::findOrFail($id);

        // Verificar que el curso no esté ya en este pensum (activo)
        $existe = PensumCurso::where('id_pensum', $id)
            ->where('id_curso', $request->id_curso)
            ->where('estado', 'activo')
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El curso ya está asociado a este pensum.',
                'errors'  => ['id_curso' => ['Este curso ya existe en el pensum.']],
            ], 422);
        }

        $pensumCurso = PensumCurso::create([
            'id_pensum'      => $id,
            'id_curso'       => $request->id_curso,
            'ciclo_semestre' => $request->ciclo_semestre,
            'estado'         => 'activo',
            'fecha_creacion' => now(),
        ]);

        HistorialService::registrar(
            tabla:      'pensum_curso',
            idRegistro: $pensumCurso->id_pensum_curso,
            tipoCambio: 'insert',
            valorNuevo: $pensumCurso->toArray(),
            motivo:     "Curso agregado al pensum {$pensum->codigo_pensum}",
        );

        return response()->json($pensumCurso->load('curso'), 201);
    }

    /**
     * DELETE /api/pensums/{pensum}/cursos/{pensumCurso}
     * Desactiva la asociación curso-pensum.
     * No elimina el curso ni el pensum.
     */
    public function quitarCurso(int $idPensum, int $idPensumCurso): JsonResponse
    {
        $pensumCurso = PensumCurso::where('id_pensum', $idPensum)
            ->where('id_pensum_curso', $idPensumCurso)
            ->firstOrFail();

        HistorialService::registrar(
            tabla:         'pensum_curso',
            idRegistro:    $pensumCurso->id_pensum_curso,
            tipoCambio:    'delete',
            valorAnterior: $pensumCurso->toArray(),
            motivo:        'Curso removido del pensum',
        );

        $pensumCurso->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Curso removido del pensum correctamente.']);
    }

    /**
     * PATCH /api/pensums/{pensum}/cursos/{pensumCurso}
     * Actualiza el ciclo_semestre de un curso en el pensum.
     */
    public function actualizarCiclo(Request $request, int $idPensum, int $idPensumCurso): JsonResponse
    {
        $request->validate([
            'ciclo_semestre' => ['required', 'integer', 'min:1', 'max:15'],
        ]);

        $pensumCurso = PensumCurso::where('id_pensum', $idPensum)
            ->where('id_pensum_curso', $idPensumCurso)
            ->firstOrFail();

        HistorialService::registrar(
            tabla:         'pensum_curso',
            idRegistro:    $pensumCurso->id_pensum_curso,
            tipoCambio:    'update',
            valorAnterior: ['ciclo_semestre' => $pensumCurso->ciclo_semestre],
            valorNuevo:    ['ciclo_semestre' => $request->ciclo_semestre],
            motivo:        'Actualización de ciclo/semestre',
        );

        $pensumCurso->update(['ciclo_semestre' => $request->ciclo_semestre]);

        return response()->json($pensumCurso->load('curso'));
    }
}
