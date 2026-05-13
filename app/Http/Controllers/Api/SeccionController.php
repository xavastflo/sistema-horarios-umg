<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seccion\StoreSeccionRequest;
use App\Http\Requests\AsignacionDocenteCurso\StoreAsignacionRequest;
use App\Models\AsignacionDocenteCurso;
use App\Models\Docente;
use App\Models\PensumCurso;
use App\Models\Seccion;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeccionController extends Controller
{
    /**
     * GET /api/secciones
     * Filtros: id_curso, id_periodo_academico, estado
     */
    public function index(Request $request): JsonResponse
    {
        $query = Seccion::with([
            'curso',
            'periodoAcademico',
            'asignacionActiva.docente.usuario',
        ])
        ->when($request->id_curso, fn($q) => $q->where('id_curso', $request->id_curso))
        ->when($request->id_periodo_academico, fn($q) => $q->where('id_periodo_academico', $request->id_periodo_academico))
        ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
        ->orderBy('id_curso')
        ->orderBy('numero_seccion');

        return response()->json($query->get());
    }

    /**
     * POST /api/secciones
     * REGLA: No se puede repetir la misma sección (curso + período + número).
     */
    public function store(StoreSeccionRequest $request): JsonResponse
    {
        // Verificar unicidad con mensaje amigable
        $existe = Seccion::where('id_curso', $request->id_curso)
            ->where('id_periodo_academico', $request->id_periodo_academico)
            ->where('numero_seccion', $request->numero_seccion)
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe una sección con ese número para este curso y período.',
                'errors'  => ['numero_seccion' => ['Sección duplicada.']],
            ], 422);
        }

        $seccion = Seccion::create([
            'id_curso'             => $request->id_curso,
            'id_periodo_academico' => $request->id_periodo_academico,
            'numero_seccion'       => strtoupper($request->numero_seccion),
            'estado'               => 'activo',
            'fecha_creacion'       => now(),
            'fecha_actualizacion'  => now(),
        ]);

        HistorialService::registrarCreacion($seccion, 'seccion');

        return response()->json($seccion->load(['curso', 'periodoAcademico']), 201);
    }

    /**
     * GET /api/secciones/{seccion}
     */
    public function show(int $id): JsonResponse
    {
        $seccion = Seccion::with([
            'curso',
            'periodoAcademico',
            'asignacionActiva.docente.usuario',
        ])->findOrFail($id);

        return response()->json($seccion);
    }

    /**
     * DELETE /api/secciones/{seccion}
     * No eliminar si tiene asignación activa.
     */
    public function destroy(int $id): JsonResponse
    {
        $seccion = Seccion::findOrFail($id);

        if ($seccion->tieneDocente()) {
            return response()->json([
                'message' => 'No se puede desactivar una sección que tiene docente asignado. Quite primero la asignación.',
            ], 422);
        }

        HistorialService::registrarEliminacion($seccion, 'seccion');
        $seccion->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Sección desactivada correctamente.']);
    }

    // ── Asignación de docentes ────────────────────────────────

    /**
     * POST /api/secciones/{seccion}/asignacion
     * Asigna un docente a la sección.
     *
     * REGLAS VALIDADAS:
     * 1. La sección no puede tener ya un docente activo (UNIQUE id_seccion en BD).
     * 2. El docente no puede tener más cursos del mismo ciclo que el límite permitido.
     * 3. MAX_CURSOS_DOCENTE validado por configuración.
     */
    public function asignarDocente(StoreAsignacionRequest $request, int $idSeccion): JsonResponse
    {
        $seccion = Seccion::with('curso')->findOrFail($idSeccion);
        $docente = Docente::where('id_docente', $request->id_docente)
            ->where('estado', 'activo')
            ->firstOrFail();

        // REGLA 1: Una sección solo puede tener un docente activo
        if ($seccion->tieneDocente()) {
            return response()->json([
                'message' => 'Esta sección ya tiene un docente asignado. Quite la asignación actual primero.',
            ], 422);
        }

        // REGLA 2: Validar máximo de cursos por docente en el período
        $maxCursos = config('academico.max_cursos_docente', 6);
        $cursosActuales = AsignacionDocenteCurso::where('id_docente', $request->id_docente)
            ->where('estado', 'activo')
            ->whereHas('seccion', fn($q) => $q->where('id_periodo_academico', $seccion->id_periodo_academico))
            ->count();

        if ($cursosActuales >= $maxCursos) {
            return response()->json([
                'message' => "El docente ya tiene {$cursosActuales} cursos asignados en este período. El máximo permitido es {$maxCursos}.",
            ], 422);
        }

        // REGLA 3: Un docente no puede tener más de un curso del mismo ciclo/semestre
        // Se obtiene el ciclo del curso en cualquier pensum activo del período
        $cicloDelCurso = PensumCurso::where('id_curso', $seccion->id_curso)
            ->where('estado', 'activo')
            ->value('ciclo_semestre');

        if ($cicloDelCurso) {
            $tieneMismoCiclo = AsignacionDocenteCurso::where('id_docente', $request->id_docente)
                ->where('estado', 'activo')
                ->whereHas('seccion', function ($q) use ($seccion) {
                    $q->where('id_periodo_academico', $seccion->id_periodo_academico);
                })
                ->whereHas('seccion.curso', function ($q) use ($cicloDelCurso) {
                    // Verificar via pensum_curso si alguno de los cursos ya asignados
                    // pertenece al mismo ciclo
                    $q->whereHas('pensums', function ($q2) use ($cicloDelCurso) {
                        $q2->wherePivot('ciclo_semestre', $cicloDelCurso)
                           ->wherePivot('estado', 'activo');
                    });
                })
                ->exists();

            if ($tieneMismoCiclo) {
                return response()->json([
                    'message' => "El docente ya tiene un curso asignado del ciclo {$cicloDelCurso} en este período. No puede impartir más de uno por ciclo.",
                ], 422);
            }
        }

        $asignacion = AsignacionDocenteCurso::create([
            'id_docente'         => $request->id_docente,
            'id_seccion'         => $idSeccion,
            'estado'             => 'activo',
            'fecha_asignacion'   => now(),
            'fecha_actualizacion'=> now(),
        ]);

        HistorialService::registrar(
            tabla:      'asignacion_docente_curso',
            idRegistro: $asignacion->id_asignacion_docente_curso,
            tipoCambio: 'asignacion',
            valorNuevo: [
                'id_docente' => $request->id_docente,
                'id_seccion' => $idSeccion,
            ],
            motivo: 'Asignación de docente a sección',
        );

        return response()->json([
            'message'    => 'Docente asignado correctamente.',
            'asignacion' => $asignacion->load(['docente.usuario', 'seccion.curso']),
        ], 201);
    }

    /**
     * DELETE /api/secciones/{seccion}/asignacion
     * Quita el docente asignado a la sección.
     */
    public function quitarDocente(int $idSeccion): JsonResponse
    {
        $seccion = Seccion::findOrFail($idSeccion);

        $asignacion = AsignacionDocenteCurso::where('id_seccion', $idSeccion)
            ->where('estado', 'activo')
            ->first();

        if (! $asignacion) {
            return response()->json([
                'message' => 'Esta sección no tiene docente asignado.',
            ], 404);
        }

        HistorialService::registrar(
            tabla:         'asignacion_docente_curso',
            idRegistro:    $asignacion->id_asignacion_docente_curso,
            tipoCambio:    'delete',
            valorAnterior: $asignacion->toArray(),
            motivo:        'Remoción de docente de sección',
        );

        $asignacion->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Docente removido de la sección correctamente.']);
    }

    /**
     * GET /api/secciones/{seccion}/asignacion
     * Consulta la asignación activa de la sección.
     */
    public function asignacion(int $idSeccion): JsonResponse
    {
        $asignacion = AsignacionDocenteCurso::with(['docente.usuario', 'seccion.curso'])
            ->where('id_seccion', $idSeccion)
            ->where('estado', 'activo')
            ->first();

        if (! $asignacion) {
            return response()->json([
                'message'    => 'Esta sección no tiene docente asignado.',
                'asignacion' => null,
            ]);
        }

        return response()->json(['asignacion' => $asignacion]);
    }
}
