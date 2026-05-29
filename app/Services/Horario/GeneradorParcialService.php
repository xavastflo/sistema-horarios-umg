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

        // ── Cargar secciones del período/carrera/jornada ────────
        // Query A: secciones con docente asignado, ordenadas por prioridad
        // REINGENIERÍA: filtradas por id_carrera_jornada para aislar la jornada
        $asignaciones = $this->cargarAsignacionesOrdenadas(
            $horario->id_carrera,
            $periodo->id_periodo_academico,
            $idCarreraJornada,
        );

        // Query B: secciones activas SIN docente asignado
        // REINGENIERÍA: filtradas por id_carrera_jornada
        $seccionesSinDocente = $this->cargarSeccionesSinDocente(
            $horario->id_carrera,
            $periodo->id_periodo_academico,
            $idCarreraJornada,
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
        $propuestas     = collect();   // todos los bloques asignados (completos + parciales)
        $noAsignables   = collect();   // secciones con 0 bloques
        $parciales      = collect();   // secciones con 1..N-1 bloques (metadato)
        $inicioTiempo   = microtime(true);

        foreach ($asignaciones as $asignacion) {

            $bloquesRequeridos = max(1, (int) ($asignacion->bloques_semanales ?? 1));
            $bloquesAsignados  = 0;

            // Días ya usados por este sección en el inner-loop.
            // Permite preferencia de días distintos (soft filter).
            $diasUsadosPorSeccion = [];

            // Último candidatos-resultado del inner-loop (para diagnóstico)
            $ultimosCandidatos = null;

            for ($numBloque = 1; $numBloque <= $bloquesRequeridos; $numBloque++) {

                // Re-invocar por cada bloque: el Filtro 4 del BloqueCandidatoService
                // (cargarBloquesOcupadosEnHorario) ve los propuestas ya elegidas
                // en iteraciones anteriores del mismo inner-loop.
                $candidatos = $this->candidatoService->obtenerCandidatos(
                    idDocente:        $asignacion->id_docente,
                    idSeccion:        $asignacion->id_seccion,
                    idHorario:        $horario->id_horario,
                    idCarreraJornada: $idCarreraJornada,
                    periodo:          $periodo,
                    horario:          $horario,
                );

                $ultimosCandidatos = $candidatos;

                // Fallo irrecuperable del contexto → detener generación completa
                $motGlobal = $candidatos->contexto()['motivo_global'] ?? null;
                if ($motGlobal && in_array($motGlobal, ['fecha_limite_vencida', 'horario_no_editable'], true)) {
                    break 2;
                }

                $cicloSeccion = (int) ($asignacion->ciclo_semestre ?? 0);

                // ── Filtro de memoria global (A, B, C — igual que antes) ───
                $candidatosFiltradosGlobal = $candidatos->bloquesValidos()->filter(
                    function ($bloque) use (
                        $asignacion,
                        $cicloSeccion,
                        $franjasOcupadasEnHorario,
                        $franjasOcupadasPorDocente,
                        $franjasOcupadasPorCiclo,
                    ) {
                        $idDia = (int) $bloque->id_dia;
                        $hi    = $bloque->hora_inicio;
                        $hf    = $bloque->hora_fin;

                        if ($this->traslapaConFranjas($franjasOcupadasEnHorario, $idDia, $hi, $hf)) {
                            return false;
                        }
                        $franjasDocente = $franjasOcupadasPorDocente[$asignacion->id_docente] ?? collect();
                        if ($this->traslapaConFranjas($franjasDocente, $idDia, $hi, $hf)) {
                            return false;
                        }
                        if ($cicloSeccion > 0) {
                            $franjasDelCiclo = $franjasOcupadasPorCiclo[$cicloSeccion] ?? collect();
                            if ($this->traslapaConFranjas($franjasDelCiclo, $idDia, $hi, $hf)) {
                                return false;
                            }
                        }
                        return true;
                    }
                );

                // ── Pasada 1: preferencia de días distintos (soft filter) ──
                if ($bloquesRequeridos > 1 && ! empty($diasUsadosPorSeccion)) {
                    $candidatosDiaDistinto = $candidatosFiltradosGlobal->filter(
                        fn($b) => ! in_array((int) $b->id_dia, $diasUsadosPorSeccion, true)
                    );
                } else {
                    $candidatosDiaDistinto = $candidatosFiltradosGlobal;
                }

                // ── Pasada 2: si la preferencia de día distinto falla, relajar ──
                $candidatosDisponibles = $candidatosDiaDistinto->isNotEmpty()
                    ? $candidatosDiaDistinto
                    : $candidatosFiltradosGlobal;

                if ($candidatosDisponibles->isEmpty()) {
                    // No hay más bloques posibles para esta sección
                    break;
                }

                // Elegir el primer bloque del conjunto filtrado (día ASC, hora ASC)
                $bloqueElegido = $candidatosDisponibles->first();

                $franja = [
                    'id_dia'      => (int) $bloqueElegido->id_dia,
                    'hora_inicio' => $bloqueElegido->hora_inicio,
                    'hora_fin'    => $bloqueElegido->hora_fin,
                ];

                // ── Actualizar estado en memoria inmediatamente ────────────
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

                $diasUsadosPorSeccion[] = (int) $bloqueElegido->id_dia;

                // ── Registrar propuesta (incluye posición y total) ─────────
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
                    numBloque:                $numBloque,
                    bloquesRequeridos:        $bloquesRequeridos,
                ));

                $bloquesAsignados++;
            } // fin inner-loop

            // ── Clasificar resultado de la sección ────────────────────────
            if ($bloquesAsignados === 0) {
                // Sin ningún bloque — diagnosticar causa
                $noAsignables->push($this->construirSeccionNoAsignable(
                    asignacion:                $asignacion,
                    candidatos:                $ultimosCandidatos,
                    franjasOcupadasEnHorario:  $franjasOcupadasEnHorario,
                    franjasOcupadasPorDocente: $franjasOcupadasPorDocente,
                    franjasOcupadasPorCiclo:   $franjasOcupadasPorCiclo,
                    cicloSeccion:              (int) ($asignacion->ciclo_semestre ?? 0),
                    bloquesAsignados:          0,
                    bloquesRequeridos:         $bloquesRequeridos,
                ));
            } elseif ($bloquesAsignados < $bloquesRequeridos) {
                // Parcial — sus bloques YA están en $propuestas y se persistirán
                $parciales->push(new SeccionNoAsignable(
                    idSeccion:         $asignacion->id_seccion,
                    nombreCurso:       $asignacion->nombre_curso,
                    numeroSeccion:     $asignacion->numero_seccion,
                    cicloSemestre:     (int) ($asignacion->ciclo_semestre ?? 0),
                    razon:             SeccionNoAsignable::ASIGNACION_PARCIAL,
                    mensaje:           "Se asignaron {$bloquesAsignados}/{$bloquesRequeridos} bloques. "
                                       . 'No se encontraron bloques disponibles para los bloques restantes.',
                    bloquesAsignados:  $bloquesAsignados,
                    bloquesRequeridos: $bloquesRequeridos,
                    bloquesDescartados: [],
                ));
            }
            // Si $bloquesAsignados === $bloquesRequeridos → completo, solo en $propuestas
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
                bloquesAsignados:  0,
                bloquesRequeridos: max(1, (int) ($seccion->bloques_semanales ?? 1)),
                bloquesDescartados: [],
            ));
        }

        $tiempoMs = round((microtime(true) - $inicioTiempo) * 1000, 2);

        $totalSeccionesEvaluadas = $asignaciones->count() + $seccionesSinDocente->count();
        $totalBloquesRequeridos  = $asignaciones->sum(fn($a) => max(1, (int) ($a->bloques_semanales ?? 1)))
                                 + $seccionesSinDocente->sum(fn($s) => max(1, (int) ($s->bloques_semanales ?? 1)));
        $totalBloquesAsignados   = $propuestas->count();

        return GeneracionParcialResultado::crear(
            asignacionesPropuestas: $propuestas,
            seccionesNoAsignables:  $noAsignables,
            seccionesParciales:     $parciales,
            estadisticas: $this->estadisticas(
                $horario, $periodo, $idCarreraJornada,
                $totalSeccionesEvaluadas,
                $totalBloquesRequeridos,
                $totalBloquesAsignados,
                $noAsignables->count(),
                $parciales->count(),
                $tiempoMs,
            ),
            idCarreraJornada: $idCarreraJornada,
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
    /**
     * Query A — Secciones con docente asignado, filtradas por jornada.
     *
     * REINGENIERÍA: ahora acepta id_carrera_jornada y filtra directamente
     * por seccion.id_carrera_jornada. Esto garantiza que al generar
     * la Matutina no se tomen secciones de la Vespertina ni de Fin de Semana.
     */
    private function cargarAsignacionesOrdenadas(
        int $idCarrera,
        int $idPeriodo,
        int $idCarreraJornada,
    ): Collection {
        $anioPeriodo = (int) DB::table('periodo_academico')
            ->where('id_periodo_academico', $idPeriodo)
            ->value('anio');

        return DB::table('asignacion_docente_curso as adc')
            ->join('docente as d',   'adc.id_docente',  '=', 'd.id_docente')
            ->join('usuario as u',   'd.id_usuario',    '=', 'u.id_usuario')
            ->join('seccion as s',   'adc.id_seccion',  '=', 's.id_seccion')
            ->join('curso as c',     's.id_curso',      '=', 'c.id_curso')
            // Filtro directo por jornada — clave de la reingeniería
            ->where('s.id_carrera_jornada', $idCarreraJornada)
            // Solo secciones que pertenecen a la carrera (via pensum activo)
            ->whereExists(function ($sub) use ($idCarrera, $anioPeriodo) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc')
                    ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
                    ->whereColumn('pc.id_curso', 's.id_curso')
                    ->where('p.id_carrera', $idCarrera)
                    ->where('p.estado', 'activo')
                    ->where('p.anio_inicio_vigencia', '<=', $anioPeriodo)
                    ->where(function ($q) use ($anioPeriodo) {
                        $q->whereNull('p.anio_fin_vigencia')
                          ->orWhere('p.anio_fin_vigencia', '>=', $anioPeriodo);
                    })
                    ->where('pc.estado', 'activo');
            })
            ->where('s.id_periodo_academico', $idPeriodo)
            ->where('adc.estado', 'activo')
            ->where('d.estado',   'activo')
            ->where('s.estado',   'activo')
            // ciclo_semestre del pensum correcto (carrera + período)
            ->leftJoin('pensum_curso as pc2', function ($join) use ($idCarrera, $anioPeriodo) {
                $join->on('pc2.id_curso', '=', 's.id_curso')
                     ->whereExists(function ($sub) use ($idCarrera, $anioPeriodo) {
                         $sub->select(DB::raw(1))
                             ->from('pensum as p2')
                             ->whereColumn('p2.id_pensum', 'pc2.id_pensum')
                             ->where('p2.id_carrera', $idCarrera)
                             ->where('p2.estado', 'activo')
                             ->where('p2.anio_inicio_vigencia', '<=', $anioPeriodo)
                             ->where(function ($q) use ($anioPeriodo) {
                                 $q->whereNull('p2.anio_fin_vigencia')
                                   ->orWhere('p2.anio_fin_vigencia', '>=', $anioPeriodo);
                             });
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
                DB::raw('COALESCE(pc2.bloques_semanales, 1) as bloques_semanales'),
            ])
            ->get();
    }

    /**
     * Query B — Secciones activas SIN docente asignado, filtradas por jornada.
     *
     * REINGENIERÍA: filtra por seccion.id_carrera_jornada para no mezclar
     * secciones de distintas jornadas de la misma carrera.
     */
    private function cargarSeccionesSinDocente(
        int $idCarrera,
        int $idPeriodo,
        int $idCarreraJornada,
    ): Collection {
        $anioPeriodo = (int) DB::table('periodo_academico')
            ->where('id_periodo_academico', $idPeriodo)
            ->value('anio');

        return DB::table('seccion as s')
            ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
            // Filtro directo por jornada
            ->where('s.id_carrera_jornada', $idCarreraJornada)
            // Solo secciones de la carrera (via pensum activo)
            ->whereExists(function ($sub) use ($idCarrera, $anioPeriodo) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc')
                    ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
                    ->whereColumn('pc.id_curso', 's.id_curso')
                    ->where('p.id_carrera', $idCarrera)
                    ->where('p.estado', 'activo')
                    ->where('p.anio_inicio_vigencia', '<=', $anioPeriodo)
                    ->where(function ($q) use ($anioPeriodo) {
                        $q->whereNull('p.anio_fin_vigencia')
                          ->orWhere('p.anio_fin_vigencia', '>=', $anioPeriodo);
                    })
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
            ->leftJoin('pensum_curso as pc2', function ($join) use ($idCarrera, $anioPeriodo) {
                $join->on('pc2.id_curso', '=', 's.id_curso')
                     ->whereExists(function ($sub) use ($idCarrera, $anioPeriodo) {
                         $sub->select(DB::raw(1))
                             ->from('pensum as p2')
                             ->whereColumn('p2.id_pensum', 'pc2.id_pensum')
                             ->where('p2.id_carrera', $idCarrera)
                             ->where('p2.estado', 'activo')
                             ->where('p2.anio_inicio_vigencia', '<=', $anioPeriodo)
                             ->where(function ($q) use ($anioPeriodo) {
                                 $q->whereNull('p2.anio_fin_vigencia')
                                   ->orWhere('p2.anio_fin_vigencia', '>=', $anioPeriodo);
                             });
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
        ?BloqueCandidatoResultado $candidatos,
        Collection               $franjasOcupadasEnHorario,
        array                    $franjasOcupadasPorDocente,
        array                    $franjasOcupadasPorCiclo,
        int                      $cicloSeccion,
        int                      $bloquesAsignados  = 0,
        int                      $bloquesRequeridos = 1,
    ): SeccionNoAsignable {

        // Sin resultado de candidatos (no se llegó a invocar)
        if ($candidatos === null) {
            return new SeccionNoAsignable(
                idSeccion:          $asignacion->id_seccion,
                nombreCurso:        $asignacion->nombre_curso,
                numeroSeccion:      $asignacion->numero_seccion,
                cicloSemestre:      $cicloSeccion,
                razon:              SeccionNoAsignable::SIN_CANDIDATOS,
                mensaje:            'No se pudo evaluar la sección.',
                bloquesAsignados:   $bloquesAsignados,
                bloquesRequeridos:  $bloquesRequeridos,
            );
        }

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
                bloquesAsignados:   $bloquesAsignados,
                bloquesRequeridos:  $bloquesRequeridos,
                bloquesDescartados: [],
            );
        }

        // Si hay candidatos válidos en BD, determinar qué filtro de memoria los bloqueó
        $validosEnBD = $candidatos->bloquesValidos();
        if ($validosEnBD->isNotEmpty()) {
            $franjasDocente  = $franjasOcupadasPorDocente[$asignacion->id_docente] ?? collect();
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
                bloquesAsignados:   $bloquesAsignados,
                bloquesRequeridos:  $bloquesRequeridos,
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
            bloquesAsignados:   $bloquesAsignados,
            bloquesRequeridos:  $bloquesRequeridos,
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
            seccionesParciales:     collect(),
            estadisticas: [
                'id_horario'                 => $horario->id_horario,
                'id_carrera'                 => $horario->id_carrera,
                'id_periodo'                 => $periodo->id_periodo_academico,
                'id_carrera_jornada'         => $idCarreraJornada,
                'razon_global'               => $razon,
                'mensaje_global'             => $mensaje,
                'total_secciones_evaluadas'  => 0,
                'total_secciones_completas'  => 0,
                'total_secciones_parciales'  => 0,
                'total_secciones_sin_bloque' => 0,
                'total_bloques_asignados'    => 0,
                'total_bloques_requeridos'   => 0,
                'completitud_bloques_pct'    => 0,
                'tiempo_ms'                  => 0,
            ],
            idCarreraJornada: $idCarreraJornada,
        );
    }

    private function estadisticas(
        Horario          $horario,
        PeriodoAcademico $periodo,
        int              $idCarreraJornada,
        int              $totalSeccionesEvaluadas,
        int              $totalBloquesRequeridos,
        int              $totalBloquesAsignados,
        int              $totalSinBloque,
        int              $totalParciales,
        float            $tiempoMs = 0,
    ): array {
        $pct = $totalBloquesRequeridos > 0
            ? round(($totalBloquesAsignados / $totalBloquesRequeridos) * 100, 1)
            : 0;

        return [
            'id_horario'                => $horario->id_horario,
            'id_carrera'                => $horario->id_carrera,
            'id_periodo'                => $periodo->id_periodo_academico,
            'id_carrera_jornada'        => $idCarreraJornada,
            'total_secciones_evaluadas' => $totalSeccionesEvaluadas,
            'total_secciones_completas' => $totalSeccionesEvaluadas - $totalSinBloque - $totalParciales,
            'total_secciones_parciales' => $totalParciales,
            'total_secciones_sin_bloque'=> $totalSinBloque,
            'total_bloques_asignados'   => $totalBloquesAsignados,
            'total_bloques_requeridos'  => $totalBloquesRequeridos,
            'completitud_bloques_pct'   => $pct,
            'tiempo_ms'                 => $tiempoMs,
        ];
    }
}
