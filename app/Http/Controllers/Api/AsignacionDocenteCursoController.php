<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AsignacionDocenteCurso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsignacionDocenteCursoController extends Controller
{
    /**
     * GET /api/asignaciones
     * Lista asignaciones docente-sección con filtros.
     * Útil para reportes y para el algoritmo de generación de horarios.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AsignacionDocenteCurso::with([
            'docente.usuario',
            'seccion.curso',
            'seccion.periodoAcademico',
        ])
        ->when($request->id_docente, fn($q) => $q->where('id_docente', $request->id_docente))
        ->when($request->id_periodo_academico, fn($q) => $q->whereHas(
            'seccion',
            fn($q2) => $q2->where('id_periodo_academico', $request->id_periodo_academico)
        ))
        ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
        ->orderBy('id_docente');

        return response()->json($query->get());
    }

    /**
     * GET /api/asignaciones/{asignacion}
     */
    public function show(int $id): JsonResponse
    {
        $asignacion = AsignacionDocenteCurso::with([
            'docente.usuario',
            'seccion.curso',
            'seccion.periodoAcademico',
        ])->findOrFail($id);

        return response()->json($asignacion);
    }

    /**
     * GET /api/asignaciones/docente/{docente}/periodo/{periodo}
     * Asignaciones de un docente en un período específico.
     * Útil para validar límite de cursos y ciclos antes de asignar.
     */
    public function porDocenteYPeriodo(int $idDocente, int $idPeriodo): JsonResponse
    {
        $asignaciones = AsignacionDocenteCurso::with([
            'seccion.curso',
            'seccion.periodoAcademico',
        ])
        ->where('id_docente', $idDocente)
        ->where('estado', 'activo')
        ->whereHas('seccion', fn($q) => $q->where('id_periodo_academico', $idPeriodo))
        ->get();

        $maxCursos = config('academico.max_cursos_docente', 6);

        return response()->json([
            'asignaciones'         => $asignaciones,
            'total_asignados'      => $asignaciones->count(),
            'max_cursos_permitido' => $maxCursos,
            'puede_asignar_mas'    => $asignaciones->count() < $maxCursos,
        ]);
    }
}
