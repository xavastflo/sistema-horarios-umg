<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Seccion\StoreSeccionRequest;
use App\Http\Requests\AsignacionDocenteCurso\StoreAsignacionRequest;
use App\Models\AsignacionDocenteCurso;
use App\Models\Docente;
use App\Models\PeriodoAcademico;
use App\Models\Seccion;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeccionController extends Controller
{
    /**
     * GET /api/secciones
     * Filtros directos: id_carrera_jornada, id_curso, id_periodo_academico, estado
     *
     * Tras la reingeniería, el filtro por jornada es un simple WHERE directo
     * (ya no requiere un whereHas con 4 joins derivados).
     */
    public function index(Request $request): JsonResponse
    {
        $query = Seccion::with([
            'carreraJornada.jornada',
            'carreraJornada.carrera',
            'curso',
            'periodoAcademico',
            'asignacionActiva.docente.usuario',
        ])
        ->when($request->id_carrera_jornada, fn($q) =>
            $q->where('id_carrera_jornada', $request->id_carrera_jornada))
        ->when($request->id_curso, fn($q) =>
            $q->where('id_curso', $request->id_curso))
        ->when($request->id_periodo_academico, fn($q) =>
            $q->where('id_periodo_academico', $request->id_periodo_academico))
        ->when($request->estado, fn($q) =>
            $q->where('estado', $request->estado))
        ->orderBy('id_carrera_jornada')
        ->orderBy('numero_seccion');

        return response()->json($query->get());
    }

    /**
     * POST /api/secciones
     * Requiere: id_carrera_jornada, id_curso, id_periodo_academico, numero_seccion
     *
     * Unicidad: (id_carrera_jornada + id_curso + id_periodo + numero_seccion)
     * → Sección A puede existir en Matutina Y en Vespertina para el mismo curso.
     * → No puede existir dos veces en la misma jornada.
     */
    public function store(StoreSeccionRequest $request): JsonResponse
    {
        // La unicidad ya la valida StoreSeccionRequest con Rule::unique compuesto.
        // Esta verificación adicional produce un mensaje de error más amigable.
        $existe = Seccion::where('id_carrera_jornada',  $request->id_carrera_jornada)
            ->where('id_curso',              $request->id_curso)
            ->where('id_periodo_academico',  $request->id_periodo_academico)
            ->where('numero_seccion',        strtoupper($request->numero_seccion))
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe esa sección para este curso, período y jornada.',
                'errors'  => ['numero_seccion' => ['Sección duplicada en esta jornada.']],
            ], 422);
        }

        // ── Validar compatibilidad ciclo_semestre ↔ tipo de período ──────
        //
        // Regla: numero_periodo = 1 (Impares) → solo ciclos 1,3,5,7,9,11
        //        numero_periodo = 2 (Pares)   → solo ciclos 2,4,6,8,10,12
        //
        // La carrera real se obtiene desde id_carrera_jornada para anclar
        // el ciclo al pensum correcto (no al primer pensum que encuentre).
        $periodo = PeriodoAcademico::find($request->id_periodo_academico);

        if ($periodo) {
            $idCarreraReal = DB::table('carrera_jornada')
                ->where('id_carrera_jornada', $request->id_carrera_jornada)
                ->value('id_carrera');

            if ($idCarreraReal) {
                $cicloDelCurso = DB::table('pensum_curso as pc')
                    ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
                    ->where('pc.id_curso',  $request->id_curso)
                    ->where('pc.estado',    'activo')
                    ->where('p.id_carrera', $idCarreraReal)
                    ->where('p.estado',     'activo')
                    ->value('pc.ciclo_semestre');

                // Error explícito si el curso no está en ningún pensum activo de la carrera
                if ($cicloDelCurso === null) {
                    return response()->json([
                        'message' => 'El curso seleccionado no pertenece al pensum activo de la carrera. '
                            . 'Verifique que el curso esté asociado a un pensum activo antes de crear la sección.',
                        'errors'  => ['id_curso' => ['El curso no pertenece al pensum activo de esta carrera.']],
                    ], 422);
                }

                // Error explícito si el ciclo no es compatible con el tipo de período
                if (! in_array($cicloDelCurso, $periodo->ciclosPermitidos(), true)) {
                    $tipoPeriodo = $periodo->esPeriodoImpar()
                        ? 'Semestres Impares — solo ciclos 1, 3, 5, 7, 9, 11'
                        : 'Semestres Pares — solo ciclos 2, 4, 6, 8, 10, 12';

                    return response()->json([
                        'message' => "El curso seleccionado pertenece al ciclo {$cicloDelCurso}, "
                            . "que no corresponde al tipo de período académico elegido ({$tipoPeriodo}).",
                        'errors'  => [
                            'id_curso' => ["Ciclo {$cicloDelCurso} no es válido para este período."],
                        ],
                    ], 422);
                }
            }
        }

        $seccion = Seccion::create([
            'id_carrera_jornada'  => $request->id_carrera_jornada,
            'id_curso'            => $request->id_curso,
            'id_periodo_academico'=> $request->id_periodo_academico,
            'numero_seccion'      => strtoupper($request->numero_seccion),
            'estado'              => 'activo',
            'fecha_creacion'      => now(),
            'fecha_actualizacion' => now(),
        ]);

        HistorialService::registrarCreacion($seccion, 'seccion');

        return response()->json(
            $seccion->load(['carreraJornada.jornada', 'curso', 'periodoAcademico']),
            201
        );
    }

    /**
     * GET /api/secciones/{seccion}
     */
    public function show(int $id): JsonResponse
    {
        $seccion = Seccion::with([
            'carreraJornada.jornada',
            'carreraJornada.carrera',
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
        // CORRECCIÓN: ciclo_semestre se obtiene anclado a la carrera real de la sección,
        // navegando seccion.id_carrera_jornada → carrera_jornada.id_carrera → pensum activo.
        $seccionConJornada = Seccion::with('carreraJornada')->findOrFail($idSeccion);
        $idCarreraReal     = $seccionConJornada->carreraJornada?->id_carrera;

        if (! $idCarreraReal) {
            return response()->json([
                'message' => 'No se pudo determinar la carrera de la sección. Verifique que tenga una carrera-jornada asignada.',
            ], 422);
        }

        $cicloDelCurso = DB::table('pensum_curso as pc')
            ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
            ->where('pc.id_curso',  $seccion->id_curso)
            ->where('pc.estado',    'activo')
            ->where('p.id_carrera', $idCarreraReal)
            ->where('p.estado',     'activo')
            ->value('pc.ciclo_semestre');

        if ($cicloDelCurso === null) {
            return response()->json([
                'message' => 'El curso no pertenece a un pensum activo de la carrera/jornada de esta sección. Verifique el pensum antes de asignar el docente.',
            ], 422);
        }

        $tieneMismoCiclo = AsignacionDocenteCurso::where('id_docente', $request->id_docente)
            ->where('estado', 'activo')
            ->whereHas('seccion', fn($q) => $q->where('id_periodo_academico', $seccion->id_periodo_academico))
            ->whereHas('seccion.carreraJornada', fn($q) => $q->where('id_carrera', $idCarreraReal))
            ->whereHas('seccion.curso', function ($q) use ($cicloDelCurso, $idCarreraReal) {
                $q->whereHas('pensumCursos', function ($q2) use ($cicloDelCurso, $idCarreraReal) {
                    $q2->where('pensum_curso.ciclo_semestre', $cicloDelCurso)
                       ->where('pensum_curso.estado', 'activo')
                       ->whereHas('pensum', fn($q3) => $q3->where('id_carrera', $idCarreraReal)->where('estado', 'activo'));
                });
            })
            ->exists();

        if ($tieneMismoCiclo) {
            return response()->json([
                'message' => "El docente ya tiene un curso asignado del ciclo {$cicloDelCurso} en esta carrera y período. No puede impartir más de uno por ciclo.",
            ], 422);
        }

        // REGLA 4 (condicional): Validación de disponibilidad docente
        // Solo se aplica si la sección ya tiene detalles de horario activos.
        // Si aún no tiene bloques asignados, no se bloquea en esta etapa.
        $bloquesAsignados = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc', 'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->where('adc.id_seccion', $idSeccion)
            ->where('dh.estado', 'activo')
            ->pluck('dh.id_bloque_horario');

        if ($bloquesAsignados->isNotEmpty()) {
            $conflictoDisponibilidad = DB::table('disponibilidad_docente')
                ->where('id_docente', $request->id_docente)
                ->whereIn('id_bloque_horario', $bloquesAsignados)
                ->where('estado', 'activo')
                ->exists();

            if ($conflictoDisponibilidad) {
                return response()->json([
                    'message' => 'El docente tiene marcada restricción de disponibilidad en uno o más bloques horarios ya asignados a esta sección. Revise la disponibilidad docente antes de asignar.',
                ], 422);
            }
        }

        // OPCIÓN B — updateOrCreate para respetar el UNIQUE(id_seccion) de la BD.
        // Si existe un registro inactivo para esta sección (estado='inactivo' tras
        // una desasignación previa), lo reactivamos con el nuevo docente en lugar
        // de intentar un INSERT que violaría el índice UNIQUE.
        // Si no existe ningún registro previo, se crea uno nuevo (comportamiento original).
        $asignacion = AsignacionDocenteCurso::updateOrCreate(
            // Clave de búsqueda: sección (el UNIQUE que choca)
            ['id_seccion' => $idSeccion],
            // Valores a establecer (creación o actualización)
            [
                'id_docente'          => $request->id_docente,
                'estado'              => 'activo',
                'fecha_asignacion'    => now(),
                'fecha_actualizacion' => now(),
            ]
        );

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
