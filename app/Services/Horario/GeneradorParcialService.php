<?php

namespace App\Services\Horario;

use App\Models\Horario;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * GeneradorParcialService
 *
 * Responsabilidad: dada una carrera, un período y una carrera-jornada,
 * intentar asignar automáticamente todas las secciones activas del
 * período a bloques horarios disponibles, respetando todas las
 * restricciones del sistema.
 *
 * IMPORTANTE — Este servicio NO persiste nada en base de datos.
 * Devuelve un GeneracionParcialResultado con:
 *   - asignacionesPropuestas: listas para ser insertadas por PersistenciaHorarioService
 *   - seccionesNoAsignables:  secciones que no pudieron asignarse, con motivo detallado
 *
 * ── Algoritmo ──────────────────────────────────────────────────────────
 *
 * 1. Validar contexto (fecha límite, estado del horario)
 * 2. Cargar secciones activas del período/carrera:
 *    a. Con asignación docente → procesadas en orden de prioridad ASC
 *    b. Sin asignación docente → directamente a SeccionNoAsignable
 * 3. Para cada asignación con docente:
 *    a. Obtener candidatos via BloqueCandidatoService (lee BD)
 *    b. Filtrar candidatos contra estado en memoria (ver abajo)
 *    c. Si hay candidatos → elegir el primero (día ASC, hora ASC)
 *    d. Registrar propuesta y actualizar estado en memoria
 *    e. Si no hay candidatos → SeccionNoAsignable con motivo
 * 4. Las secciones sin docente se agregan al final como noAsignables
 *
 * ── Estado en memoria (corrección crítica) ─────────────────────────────
 *
 * BloqueCandidatoService lee detalle_horario de BD. Como las propuestas
 * de esta generación aún no están persistidas, el servicio no las ve.
 * Por eso se mantienen tres estructuras en memoria que se actualizan
 * con cada propuesta aceptada, y se aplican como filtros adicionales
 * sobre los candidatos válidos del servicio ANTES de elegir el bloque.
 *
 * Las tres estructuras usan traslape real (id_dia + hora_inicio/hora_fin),
 * NO comparación por id_bloque_horario, para detectar conflictos entre
 * bloques de distintas carreras-jornadas que coincidan en tiempo.
 *
 *   $franjasOcupadasEnHorario → Collection de franjas ya propuestas
 *     (sin importar docente). Evita dos secciones en la misma franja.
 *
 *   $franjasOcupadasPorDocente → mapa id_docente → Collection<franjas>
 *     Evita que el mismo docente aparezca en franjas traslapadas.
 *
 *   $franjasOcupadasPorCiclo → mapa ciclo_semestre → Collection<franjas>
 *     Evita que el mismo ciclo tenga dos clases en franjas traslapadas.
 *
 * ── Orden de prioridad ─────────────────────────────────────────────────
 *
 * docente.prioridad ASC → 1 (alta) se procesa primero.
 * Empate → id_asignacion ASC (orden de creación, determinista).
 */
class GeneradorParcialService
{
    public function __construct(
        private readonly BloqueCandidatoService    $candidatoService,
        private readonly ConflictValidationService $conflictService,
    ) {}

    // ── Punto de entrada ────────────────────────────────────────

    /**
     * Genera el horario parcial para una carrera-jornada.
     *
     * @param Horario          $horario          Horario en estado borrador/generado
     * @param PeriodoAcademico $periodo          Modelo hidratado del período
     * @param int              $idCarreraJornada Carrera-jornada de la que usar bloques
     */
    public function generar(
        Horario          $horario,
        PeriodoAcademico $periodo,
        int              $idCarreraJornada,
    ): GeneracionParcialResultado {

        // ── Validación de contexto (sin queries adicionales) ────
        $rFecha = $this->conflictService->validarFechaLimite($periodo);
        if ($rFecha->tieneConflictos()) {
            return $this->resultadoContextoInvalido(
                'fecha_limite_vencida',
                'El período académico superó la fecha límite de edición de horarios.',
                $horario, $periodo, $idCarreraJornada,
            );
        }

        $rEstado = $this->conflictService->validarEstadoHorario($horario);
        if ($rEstado->tieneConflictos()) {
            $estado = $rEstado->primerConflicto()?->contexto['estado_horario'] ?? '';
            return $this->resultadoContextoInvalido(
                'horario_no_editable',
                "El horario está en estado '{$estado}' y no puede modificarse.",
                $horario, $periodo, $idCarreraJornada,
            );
        }

        // ── Cargar secciones del período/carrera ────────────────
        // Query A: secciones con docente asignado, ordenadas por prioridad
        $asignaciones = $this->cargarAsignacionesOrdenadas(
            $horario->id_carrera,
            $periodo->id_periodo_academico,
        );

        // Query B: secciones activas SIN docente asignado
        $seccionesSinDocente = $this->cargarSeccionesSinDocente(
            $horario->id_carrera,
            $periodo->id_periodo_academico,
        );

        // ── Estado en memoria ───────────────────────────────────
        // Franjas ya propuestas en esta generación (no están en BD todavía).
        // Cada franja: { id_dia, hora_inicio, hora_fin }
        // Se usan traslapes reales, NO comparación por id_bloque_horario.

        /** @var Collection Franjas ocupadas en el horario (cualquier sección) */
        $franjasOcupadasEnHorario = collect();

        /** @var array<int, Collection> Mapa id_docente → Collection de franjas */
        $franjasOcupadasPorDocente = [];

        /** @var array<int, Collection> Mapa ciclo_semestre → Collection de franjas */
        $franjasOcupadasPorCiclo = [];

        // ── Procesamiento ───────────────────────────────────────
        $propuestas    = collect();
        $noAsignables  = collect();
        $inicioTiempo  = microtime(true);

        foreach ($asignaciones as $asignacion) {

            // Obtener candidatos válidos (lee BD — no ve propuestas en memoria)
            $candidatos = $this->candidatoService->obtenerCandidatos(
                idDocente:        $asignacion->id_docente,
                idSeccion:        $asignacion->id_seccion,
                idHorario:        $horario->id_horario,
                idCarreraJornada: $idCarreraJornada,
                periodo:          $periodo,
                horario:          $horario,
            );

            // Fallo irrecuperable del servicio (fecha o estado) → detener
            $motGlobal = $candidatos->contexto()['motivo_global'] ?? null;
            if ($motGlobal && in_array($motGlobal, ['fecha_limite_vencida', 'horario_no_editable'], true)) {
                break;
            }

            // Filtrar candidatos contra el estado en memoria usando traslape real
            $cicloSeccion = (int) ($asignacion->ciclo_semestre ?? 0);

            $candidatosDisponibles = $candidatos->bloquesValidos()->filter(
                function ($bloque) use (
                    $asignacion,
                    $cicloSeccion,
                    $franjasOcupadasEnHorario,
                    $franjasOcupadasPorDocente,
                    $franjasOcupadasPorCiclo,
                ) {
                    $idDia      = (int) $bloque->id_dia;
                    $horaInicio = $bloque->hora_inicio;
                    $horaFin    = $bloque->hora_fin;

                    // Filtro A: franja ya ocupada en el horario (cualquier sección)
                    if ($this->traslapaConFranjas($franjasOcupadasEnHorario, $idDia, $horaInicio, $horaFin)) {
                        return false;
                    }

                    // Filtro B: docente ya tiene propuesta en esta franja
                    $franjasDocente = $franjasOcupadasPorDocente[$asignacion->id_docente] ?? collect();
                    if ($this->traslapaConFranjas($franjasDocente, $idDia, $horaInicio, $horaFin)) {
                        return false;
                    }

                    // Filtro C: ciclo ya tiene propuesta en esta franja
                    if ($cicloSeccion > 0) {
                        $franjasDelCiclo = $franjasOcupadasPorCiclo[$cicloSeccion] ?? collect();
                        if ($this->traslapaConFranjas($franjasDelCiclo, $idDia, $horaInicio, $horaFin)) {
                            return false;
                        }
                    }

                    return true;
                }
            );

            if ($candidatosDisponibles->isEmpty()) {
                $noAsignables->push($this->construirSeccionNoAsignable(
                    asignacion:               $asignacion,
                    candidatos:               $candidatos,
                    franjasOcupadasEnHorario: $franjasOcupadasEnHorario,
                    franjasOcupadasPorDocente: $franjasOcupadasPorDocente,
                    franjasOcupadasPorCiclo:  $franjasOcupadasPorCiclo,
                    cicloSeccion:             $cicloSeccion,
                ));
                continue;
            }

            // Tomar el primer bloque (día ASC, hora ASC — orden del servicio)
            $bloqueElegido = $candidatosDisponibles->first();

            // Construir franja para el estado en memoria
            $franja = [
                'id_dia'      => (int) $bloqueElegido->id_dia,
                'hora_inicio' => $bloqueElegido->hora_inicio,
                'hora_fin'    => $bloqueElegido->hora_fin,
            ];

            // Actualizar estado en memoria
            $franjasOcupadasEnHorario->push($franja);

            if (! isset($franjasOcupadasPorDocente[$asignacion->id_docente])) {
                $franjasOcupadasPorDocente[$asignacion->id_docente] = collect();
            }
            $franjasOcupadasPorDocente[$asignacion->id_docente]->push($franja);

            if ($cicloSeccion > 0) {
                if (! isset($franjasOcupadasPorCiclo[$cicloSeccion])) {
                    $franjasOcupadasPorCiclo[$cicloSeccion] = collect();
                }
                $franjasOcupadasPorCiclo[$cicloSeccion]->push($franja);
            }

            // Registrar propuesta
            $propuestas->push(new AsignacionPropuesta(
                idAsignacionDocenteCurso: $asignacion->id_asignacion_docente_curso,
                idSeccion:                $asignacion->id_seccion,
                idDocente:                $asignacion->id_docente,
                idBloque:                 $bloqueElegido->id_bloque_horario,
                idDia:                    (int) $bloqueElegido->id_dia,
                horaInicio:               $bloqueElegido->hora_inicio,
                horaFin:                  $bloqueElegido->hora_fin,
                nombreDia:                $bloqueElegido->getAttribute('nombre_dia') ?? '',
                nombreCurso:              $asignacion->nombre_curso,
                nombreDocente:            $asignacion->nombre_docente,
                cicloSemestre:            $cicloSeccion,
                prioridadDocente:         (int) $asignacion->prioridad,
            ));
        }

        // Agregar secciones sin docente al resultado de no-asignables
        foreach ($seccionesSinDocente as $seccion) {
            $noAsignables->push(new SeccionNoAsignable(
                idSeccion:         $seccion->id_seccion,
                nombreCurso:       $seccion->nombre_curso,
                numeroSeccion:     $seccion->numero_seccion,
                cicloSemestre:     (int) ($seccion->ciclo_semestre ?? 0),
                razon:             SeccionNoAsignable::SIN_ASIGNACION_DOCENTE,
                mensaje:           'La sección no tiene docente asignado. Asigne un docente antes de generar el horario.',
                bloquesDescartados: [],
            ));
        }

        $tiempoMs = round((microtime(true) - $inicioTiempo) * 1000, 2);

        $totalEvaluadas = $asignaciones->count() + $seccionesSinDocente->count();

        return GeneracionParcialResultado::crear(
            asignacionesPropuestas: $propuestas,
            seccionesNoAsignables:  $noAsignables,
            estadisticas: $this->estadisticas(
                $horario, $periodo, $idCarreraJornada,
                $totalEvaluadas,
                $propuestas->count(),
                $noAsignables->count(),
                $tiempoMs,
            ),
        );
    }

    // ── Función de traslape en memoria ──────────────────────────

    /**
     * Verifica si la franja candidata [idDia, horaInicio, horaFin)
     * traslapa con alguna de las franjas en la colección.
     *
     * Condición de traslape:
     *   franja.id_dia     = idDia
     *   franja.hora_inicio < horaFin      (la franja existente empieza antes de que termine el candidato)
     *   franja.hora_fin    > horaInicio   (la franja existente termina después de que empieza el candidato)
     *
     * Compara strings "HH:MM:SS" directamente — MySQL time format,
     * comparación lexicográfica correcta para tiempos del mismo día.
     */
    private function traslapaConFranjas(
        Collection $franjas,
        int        $idDia,
        string     $horaInicio,
        string     $horaFin,
    ): bool {
        foreach ($franjas as $franja) {
            if (
                (int) $franja['id_dia'] === $idDia
                && $franja['hora_inicio'] < $horaFin
                && $franja['hora_fin']    > $horaInicio
            ) {
                return true;
            }
        }
        return false;
    }

    // ── Carga de datos ──────────────────────────────────────────

    /**
     * Query A — Secciones CON docente asignado, ordenadas prioridad ASC.
     *
     * Incluye ciclo_semestre del pensum activo de esta carrera/período
     * para los filtros de traslape de ciclo en memoria.
     */
    private function cargarAsignacionesOrdenadas(
        int $idCarrera,
        int $idPeriodo,
    ): Collection {
        return DB::table('asignacion_docente_curso as adc')
            ->join('docente as d',   'adc.id_docente',  '=', 'd.id_docente')
            ->join('usuario as u',   'd.id_usuario',    '=', 'u.id_usuario')
            ->join('seccion as s',   'adc.id_seccion',  '=', 's.id_seccion')
            ->join('curso as c',     's.id_curso',      '=', 'c.id_curso')
            // Solo secciones que pertenecen a la carrera (via pensum activo)
            ->whereExists(function ($sub) use ($idCarrera, $idPeriodo) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc')
                    ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
                    ->whereColumn('pc.id_curso', 's.id_curso')
                    ->where('p.id_carrera', $idCarrera)
                    ->where('p.id_periodo_academico', $idPeriodo)
                    ->where('p.estado', 'activo')
                    ->where('pc.estado', 'activo');
            })
            ->where('s.id_periodo_academico', $idPeriodo)
            ->where('adc.estado', 'activo')
            ->where('d.estado',   'activo')
            ->where('s.estado',   'activo')
            // ciclo_semestre del pensum correcto (carrera + período)
            ->leftJoin('pensum_curso as pc2', function ($join) use ($idCarrera, $idPeriodo) {
                $join->on('pc2.id_curso', '=', 's.id_curso')
                     ->whereExists(function ($sub) use ($idCarrera, $idPeriodo) {
                         $sub->select(DB::raw(1))
                             ->from('pensum as p2')
                             ->whereColumn('p2.id_pensum', 'pc2.id_pensum')
                             ->where('p2.id_carrera', $idCarrera)
                             ->where('p2.id_periodo_academico', $idPeriodo)
                             ->where('p2.estado', 'activo');
                     })
                     ->where('pc2.estado', 'activo');
            })
            ->orderBy('d.prioridad',                        'asc')  // 1=alta primero
            ->orderBy('adc.id_asignacion_docente_curso',    'asc')  // estabilidad
            ->select([
                'adc.id_asignacion_docente_curso',
                'adc.id_docente',
                's.id_seccion',
                's.numero_seccion',
                'c.nombre_curso',
                DB::raw("CONCAT(u.nombres, ' ', u.apellidos) as nombre_docente"),
                'd.prioridad',
                'pc2.ciclo_semestre',
            ])
            ->get();
    }

    /**
     * Query B — Secciones activas SIN docente asignado.
     *
     * Se excluyen las secciones que ya tienen asignación activa.
     * Se incluye ciclo_semestre para informar al coordinador.
     */
    private function cargarSeccionesSinDocente(
        int $idCarrera,
        int $idPeriodo,
    ): Collection {
        return DB::table('seccion as s')
            ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
            // Solo secciones de la carrera (via pensum activo)
            ->whereExists(function ($sub) use ($idCarrera, $idPeriodo) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc')
                    ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
                    ->whereColumn('pc.id_curso', 's.id_curso')
                    ->where('p.id_carrera', $idCarrera)
                    ->where('p.id_periodo_academico', $idPeriodo)
                    ->where('p.estado', 'activo')
                    ->where('pc.estado', 'activo');
            })
            // Sin asignación docente activa
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('asignacion_docente_curso as adc')
                    ->whereColumn('adc.id_seccion', 's.id_seccion')
                    ->where('adc.estado', 'activo');
            })
            ->where('s.id_periodo_academico', $idPeriodo)
            ->where('s.estado', 'activo')
            // ciclo_semestre del pensum correcto
            ->leftJoin('pensum_curso as pc2', function ($join) use ($idCarrera, $idPeriodo) {
                $join->on('pc2.id_curso', '=', 's.id_curso')
                     ->whereExists(function ($sub) use ($idCarrera, $idPeriodo) {
                         $sub->select(DB::raw(1))
                             ->from('pensum as p2')
                             ->whereColumn('p2.id_pensum', 'pc2.id_pensum')
                             ->where('p2.id_carrera', $idCarrera)
                             ->where('p2.id_periodo_academico', $idPeriodo)
                             ->where('p2.estado', 'activo');
                     })
                     ->where('pc2.estado', 'activo');
            })
            ->orderBy('c.nombre_curso')
            ->orderBy('s.numero_seccion')
            ->select([
                's.id_seccion',
                's.numero_seccion',
                'c.nombre_curso',
                'pc2.ciclo_semestre',
            ])
            ->get();
    }

    // ── Construcción de SeccionNoAsignable ──────────────────────

    /**
     * Determina el motivo exacto de no asignación y construye el DTO.
     *
     * Casos (en orden de diagnóstico):
     *   C3: Sin bloques definidos en la carrera-jornada
     *   C2a: Candidatos válidos según BD, pero bloqueados por docente ocupado en memoria
     *   C2b: Candidatos válidos según BD, pero bloqueados por franja del horario en memoria
     *   C2c: Candidatos válidos según BD, pero bloqueados por ciclo en memoria
     *   C1: Sin candidatos por restricciones de BD (disponibilidad, ciclo, etc.)
     */
    private function construirSeccionNoAsignable(
        object                   $asignacion,
        BloqueCandidatoResultado $candidatos,
        Collection               $franjasOcupadasEnHorario,
        array                    $franjasOcupadasPorDocente,
        array                    $franjasOcupadasPorCiclo,
        int                      $cicloSeccion,
    ): SeccionNoAsignable {

        // C3: sin bloques definidos
        $contexto = $candidatos->contexto();
        if (($contexto['motivo_global'] ?? null) === 'sin_bloques_definidos') {
            return new SeccionNoAsignable(
                idSeccion:          $asignacion->id_seccion,
                nombreCurso:        $asignacion->nombre_curso,
                numeroSeccion:      $asignacion->numero_seccion,
                cicloSemestre:      $cicloSeccion,
                razon:              SeccionNoAsignable::SIN_BLOQUES_DEFINIDOS,
                mensaje:            'No existen bloques horarios definidos para esta carrera-jornada.',
                bloquesDescartados: [],
            );
        }

        // Si hay candidatos válidos en BD, determinar qué filtro de memoria los bloqueó
        $validosEnBD = $candidatos->bloquesValidos();
        if ($validosEnBD->isNotEmpty()) {
            // Identificar qué filtro rechazó todos los candidatos válidos
            $franjasDocente = $franjasOcupadasPorDocente[$asignacion->id_docente] ?? collect();
            $franjasDelCiclo = $cicloSeccion > 0
                ? ($franjasOcupadasPorCiclo[$cicloSeccion] ?? collect())
                : collect();

            $motivosMemoria = [];

            foreach ($validosEnBD as $bloque) {
                $idDia = (int) $bloque->id_dia;
                $hi    = $bloque->hora_inicio;
                $hf    = $bloque->hora_fin;
                $dia   = $bloque->getAttribute('nombre_dia') ?? "día {$idDia}";

                if ($this->traslapaConFranjas($franjasOcupadasEnHorario, $idDia, $hi, $hf)) {
                    $motivosMemoria[] = "Bloque {$dia} {$hi}-{$hf}: franja ya ocupada en este horario por otra sección.";
                } elseif ($this->traslapaConFranjas($franjasDocente, $idDia, $hi, $hf)) {
                    $motivosMemoria[] = "Bloque {$dia} {$hi}-{$hf}: docente ya tiene otra clase asignada en esta generación.";
                } elseif ($cicloSeccion > 0 && $this->traslapaConFranjas($franjasDelCiclo, $idDia, $hi, $hf)) {
                    $motivosMemoria[] = "Bloque {$dia} {$hi}-{$hf}: ciclo {$cicloSeccion} ya tiene clase en esta franja.";
                }
            }

            return new SeccionNoAsignable(
                idSeccion:          $asignacion->id_seccion,
                nombreCurso:        $asignacion->nombre_curso,
                numeroSeccion:      $asignacion->numero_seccion,
                cicloSemestre:      $cicloSeccion,
                razon:              SeccionNoAsignable::SIN_CANDIDATOS,
                mensaje:            'Los bloques válidos están ocupados por otras asignaciones de esta misma generación.',
                bloquesDescartados: array_map(fn($m) => ['motivo_memoria' => $m], $motivosMemoria),
            );
        }

        // C1: sin candidatos por restricciones de BD
        return new SeccionNoAsignable(
            idSeccion:          $asignacion->id_seccion,
            nombreCurso:        $asignacion->nombre_curso,
            numeroSeccion:      $asignacion->numero_seccion,
            cicloSemestre:      $cicloSeccion,
            razon:              SeccionNoAsignable::SIN_CANDIDATOS,
            mensaje:            'No existe ningún bloque disponible que cumpla todas las restricciones.',
            bloquesDescartados: $candidatos->bloquesDescartados()
                ->map(fn($d) => $d->toArray())
                ->values()
                ->all(),
        );
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function resultadoContextoInvalido(
        string           $razon,
        string           $mensaje,
        Horario          $horario,
        PeriodoAcademico $periodo,
        int              $idCarreraJornada,
    ): GeneracionParcialResultado {
        return GeneracionParcialResultado::crear(
            asignacionesPropuestas: collect(),
            seccionesNoAsignables:  collect(),
            estadisticas: [
                'id_horario'         => $horario->id_horario,
                'id_carrera'         => $horario->id_carrera,
                'id_periodo'         => $periodo->id_periodo_academico,
                'id_carrera_jornada' => $idCarreraJornada,
                'razon_global'       => $razon,
                'mensaje_global'     => $mensaje,
                'total_evaluadas'    => 0,
                'total_asignadas'    => 0,
                'total_no_asignadas' => 0,
                'completitud_pct'    => 0,
                'tiempo_ms'          => 0,
            ],
        );
    }

    private function estadisticas(
        Horario          $horario,
        PeriodoAcademico $periodo,
        int              $idCarreraJornada,
        int              $totalEvaluadas,
        int              $totalAsignadas,
        int              $totalNoAsignadas,
        float            $tiempoMs = 0,
    ): array {
        $pct = $totalEvaluadas > 0
            ? round(($totalAsignadas / $totalEvaluadas) * 100, 1)
            : 0;

        return [
            'id_horario'         => $horario->id_horario,
            'id_carrera'         => $horario->id_carrera,
            'id_periodo'         => $periodo->id_periodo_academico,
            'id_carrera_jornada' => $idCarreraJornada,
            'total_evaluadas'    => $totalEvaluadas,
            'total_asignadas'    => $totalAsignadas,
            'total_no_asignadas' => $totalNoAsignadas,
            'completitud_pct'    => $pct,
            'tiempo_ms'          => $tiempoMs,
        ];
    }
}
