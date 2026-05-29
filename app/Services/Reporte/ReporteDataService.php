<?php

namespace App\Services\Reporte;

use App\Services\Horario\HorarioConsultaService;
use Illuminate\Support\Facades\DB;

/**
 * ReporteDataService — Sprint 4, Paso 3
 *
 * Centraliza la obtención de datos para los 4 reportes.
 * Reutiliza HorarioConsultaService cuando el método existe;
 * agrega queries propias para los casos específicos de reportes.
 *
 * Reglas de negocio de permisos:
 *   - Coordinador: se pasa id_usuario_coordinador → verificarCarreraCoordinador()
 *     lo aplica internamente. Sin duplicar lógica.
 *   - Docente: el controller fuerza id_docente = docente autenticado.
 *     Este servicio solo recibe el id final, sin saber qué rol lo llamó.
 *
 * ciclo_semestre:
 *   Siempre resuelto via Horario.id_carrera + Horario.id_periodo_academico
 *   → Pensum activo → Pensum_Curso. Mismo criterio aprobado en Sprint 3.
 */
class ReporteDataService
{
    public function __construct(
        private readonly HorarioConsultaService $consultaService,
    ) {}

    // ── Reporte 1: Horario completo por carrera/período ─────────

    /**
     * Datos para el reporte de horario por carrera y período.
     * Reutiliza HorarioConsultaService::detallesCompletos() que ya
     * resuelve ciclo_semestre, jornada y estado correctamente.
     *
     * @param int      $idHorario
     * @param int|null $idCoord   null = admin (sin restricción)
     */
    public function horarioCarrera(int $idHorario, ?int $idCoord = null): array
    {
        return $this->consultaService->detallesCompletos(
            idHorario:            $idHorario,
            idUsuarioCoordinador: $idCoord,
        );
    }

    // ── Reporte 2: Horario de un docente ────────────────────────

    /**
     * Clases de un docente, filtradas por período y opcionalmente carrera.
     * Reutiliza la base de HorarioConsultaService::porDocente() y agrega
     * los filtros de período, carrera y restricción de coordinador.
     *
     * Si el coordinador intenta ver un docente de otra carrera, los filtros
     * WHERE lo excluyen naturalmente (no abortamos con 403 porque un docente
     * puede tener clases en varias carreras y el reporte muestra solo
     * las del coordinador).
     *
     * @param int      $idDocente
     * @param int|null $idPeriodo    null = todos los períodos
     * @param int|null $idCarrera    null = todas las carreras accesibles
     * @param int|null $idCoord      null = admin (sin restricción de carrera)
     */
    public function horarioDocente(
        int  $idDocente,
        ?int $idPeriodo = null,
        ?int $idCarrera = null,
        ?int $idCoord   = null,
    ): array {
        $query = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('horario as h',       'dh.id_horario',       '=', 'h.id_horario')
            ->join('carrera as ca',      'h.id_carrera',        '=', 'ca.id_carrera')
            ->join('periodo_academico as p', 'h.id_periodo_academico', '=', 'p.id_periodo_academico')
            ->join('estado_horario as eh',   'h.id_estado_horario', '=', 'eh.id_estado_horario')
            ->join('seccion as s',       'adc.id_seccion',     '=', 's.id_seccion')
            ->join('curso as c',         's.id_curso',          '=', 'c.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('carrera_jornada as cj', 'bh.id_carrera_jornada', '=', 'cj.id_carrera_jornada')
            ->join('jornada as j',       'cj.id_jornada',      '=', 'j.id_jornada')
            ->join('dia',                'dh.id_dia',           '=', 'dia.id_dia')
            ->where('adc.id_docente', $idDocente)
            ->where('dh.estado', 'activo')
            ->where('adc.estado', 'activo');

        // Filtro de período
        if ($idPeriodo !== null) {
            $query->where('h.id_periodo_academico', $idPeriodo);
        }

        // Filtro de carrera explícita
        if ($idCarrera !== null) {
            $query->where('h.id_carrera', $idCarrera);
        }

        // Restricción de coordinador: solo carreras que coordina
        if ($idCoord !== null) {
            $query->where('ca.id_usuario_coordinador', $idCoord);
        }

        $clases = $query
            ->orderByDesc('p.anio')
            ->orderByDesc('p.numero_periodo')
            ->orderBy('dia.orden_semana')
            ->orderBy('bh.hora_inicio')
            ->select([
                'h.id_horario',
                'eh.nombre_estado as estado_horario',
                'ca.nombre_carrera',
                'ca.codigo_carrera',
                'p.nombre_periodo',
                'p.anio',
                'p.numero_periodo',
                'c.id_curso',        // necesario para resolver ciclo_semestre por id
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                'dia.nombre_dia',
                'bh.hora_inicio',
                'bh.hora_fin',
                'bh.duracion_minutos',
                'j.nombre_jornada',
                // ciclo_semestre se resuelve en el map usando id_curso + id_pensum
            ])
            ->get();

        // Resolver ciclo_semestre por horario:
        // Horario → id_carrera + año(periodo_academico) → Pensum vigente → Pensum_Curso
        // REFACTOR PENSUM: ya no usa p.id_periodo_academico (campo eliminado).
        // Cache id_horario → id_pensum para evitar queries repetidas.
        $pensumCache = [];

        return $clases->map(function ($row) use (&$pensumCache) {
            $idHorario = $row->id_horario;

            if (! array_key_exists($idHorario, $pensumCache)) {
                // Obtener año del período del horario
                $anio = (int) DB::table('horario as h')
                    ->join('periodo_academico as pa', 'h.id_periodo_academico', '=', 'pa.id_periodo_academico')
                    ->where('h.id_horario', $idHorario)
                    ->value('pa.anio');

                $idCarreraH = (int) DB::table('horario')
                    ->where('id_horario', $idHorario)
                    ->value('id_carrera');

                // Pensum vigente para la carrera en ese año (más reciente primero)
                $pensumCache[$idHorario] = ($anio && $idCarreraH)
                    ? DB::table('pensum')
                        ->where('id_carrera', $idCarreraH)
                        ->where('estado', 'activo')
                        ->where('anio_inicio_vigencia', '<=', $anio)
                        ->where(function ($q) use ($anio) {
                            $q->whereNull('anio_fin_vigencia')
                              ->orWhere('anio_fin_vigencia', '>=', $anio);
                        })
                        ->orderBy('anio_inicio_vigencia', 'desc')
                        ->value('id_pensum')
                    : null;
            }

            $idPensum = $pensumCache[$idHorario];
            $ciclo    = null;

            if ($idPensum && isset($row->id_curso)) {
                // id_curso viene directo del select — sin riesgo de ambigüedad por nombre
                $ciclo = DB::table('pensum_curso')
                    ->where('id_pensum', $idPensum)
                    ->where('id_curso',  $row->id_curso)
                    ->where('estado',    'activo')
                    ->value('ciclo_semestre');
            }

            return (object) array_merge((array) $row, ['ciclo_semestre' => $ciclo]);
        })->toArray();
    }

    // ── Reporte 3: Secciones no asignadas ───────────────────────

    /**
     * Secciones con problema de asignación, en dos categorías:
     *
     * SIN_DOCENTE: sección activa sin asignacion_docente_curso activa.
     *   → El problema está en la gestión de asignaciones (Sprint 2).
     *
     * SIN_BLOQUE_EN_HORARIO: sección con docente asignado, pero sin
     *   detalle_horario activo en el horario indicado.
     *   → El problema está en la generación del horario (Sprint 3).
     *
     * @param int      $idCarrera
     * @param int      $idPeriodo
     * @param int      $idHorario   Horario donde se verifica el detalle
     * @param int|null $idCoord     null = admin
     */
    public function seccionesNoAsignadas(
        int  $idCarrera,
        int  $idPeriodo,
        int  $idHorario,
        ?int $idCoord = null,
    ): array {
        if ($idCoord !== null) {
            $this->verificarCarreraCoordinador($idCarrera, $idCoord);
        }

        // Verificar que el horario pertenece a la carrera y período indicados
        $this->verificarHorarioPerteneceACarreraYPeriodo($idHorario, $idCarrera, $idPeriodo);

        // Año del período — necesario para resolver el pensum vigente.
        // REFACTOR PENSUM: ya no se usa p.id_periodo_academico en la tabla pensum.
        $anio = (int) DB::table('periodo_academico')
            ->where('id_periodo_academico', $idPeriodo)
            ->value('anio');

        // ── Categoría 1: SIN_DOCENTE ──────────────────────────────
        $sinDocente = DB::table('seccion as s')
            ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
            ->leftJoin('pensum_curso as pc', function ($join) use ($idCarrera, $anio) {
                $join->on('pc.id_curso', '=', 's.id_curso')
                     ->whereExists(function ($sub) use ($idCarrera, $anio) {
                         $sub->select(DB::raw(1))
                             ->from('pensum as p')
                             ->whereColumn('p.id_pensum', 'pc.id_pensum')
                             ->where('p.id_carrera', $idCarrera)
                             ->where('p.estado', 'activo')
                             ->where('p.anio_inicio_vigencia', '<=', $anio)
                             ->where(function ($q) use ($anio) {
                                 $q->whereNull('p.anio_fin_vigencia')
                                   ->orWhere('p.anio_fin_vigencia', '>=', $anio);
                             });
                     })
                     ->where('pc.estado', 'activo');
            })
            // Solo secciones de esta carrera (via pensum vigente del año)
            ->whereExists(function ($sub) use ($idCarrera, $anio) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc2')
                    ->join('pensum as p2', 'pc2.id_pensum', '=', 'p2.id_pensum')
                    ->whereColumn('pc2.id_curso', 's.id_curso')
                    ->where('p2.id_carrera', $idCarrera)
                    ->where('p2.estado', 'activo')
                    ->where('p2.anio_inicio_vigencia', '<=', $anio)
                    ->where(function ($q) use ($anio) {
                        $q->whereNull('p2.anio_fin_vigencia')
                          ->orWhere('p2.anio_fin_vigencia', '>=', $anio);
                    })
                    ->where('pc2.estado', 'activo');
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
            ->orderBy('pc.ciclo_semestre')
            ->orderBy('c.nombre_curso')
            ->select([
                's.id_seccion',
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                'pc.ciclo_semestre',
                DB::raw("'SIN_DOCENTE' as categoria"),
                DB::raw("'La sección no tiene docente asignado' as motivo"),
            ])
            ->get();

        // ── Categoría 2: SIN_BLOQUE_EN_HORARIO ───────────────────
        // Sección con docente, pero sin detalle_horario activo en este horario
        $sinBloqueEnHorario = DB::table('seccion as s')
            ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
            ->join('asignacion_docente_curso as adc', function ($join) {
                $join->on('adc.id_seccion', '=', 's.id_seccion')
                     ->where('adc.estado', 'activo');
            })
            ->leftJoin('pensum_curso as pc', function ($join) use ($idCarrera, $anio) {
                $join->on('pc.id_curso', '=', 's.id_curso')
                     ->whereExists(function ($sub) use ($idCarrera, $anio) {
                         $sub->select(DB::raw(1))
                             ->from('pensum as p')
                             ->whereColumn('p.id_pensum', 'pc.id_pensum')
                             ->where('p.id_carrera', $idCarrera)
                             ->where('p.estado', 'activo')
                             ->where('p.anio_inicio_vigencia', '<=', $anio)
                             ->where(function ($q) use ($anio) {
                                 $q->whereNull('p.anio_fin_vigencia')
                                   ->orWhere('p.anio_fin_vigencia', '>=', $anio);
                             });
                     })
                     ->where('pc.estado', 'activo');
            })
            ->whereExists(function ($sub) use ($idCarrera, $anio) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc2')
                    ->join('pensum as p2', 'pc2.id_pensum', '=', 'p2.id_pensum')
                    ->whereColumn('pc2.id_curso', 's.id_curso')
                    ->where('p2.id_carrera', $idCarrera)
                    ->where('p2.estado', 'activo')
                    ->where('p2.anio_inicio_vigencia', '<=', $anio)
                    ->where(function ($q) use ($anio) {
                        $q->whereNull('p2.anio_fin_vigencia')
                          ->orWhere('p2.anio_fin_vigencia', '>=', $anio);
                    })
                    ->where('pc2.estado', 'activo');
            })
            // Sin detalle_horario activo en ESTE horario
            ->whereNotExists(function ($sub) use ($idHorario) {
                $sub->select(DB::raw(1))
                    ->from('detalle_horario as dh')
                    ->whereColumn('dh.id_asignacion_docente_curso', 'adc.id_asignacion_docente_curso')
                    ->where('dh.id_horario', $idHorario)
                    ->where('dh.estado', 'activo');
            })
            ->where('s.id_periodo_academico', $idPeriodo)
            ->where('s.estado', 'activo')
            ->orderBy('pc.ciclo_semestre')
            ->orderBy('c.nombre_curso')
            ->select([
                's.id_seccion',
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                'pc.ciclo_semestre',
                DB::raw("'SIN_BLOQUE_EN_HORARIO' as categoria"),
                DB::raw("'Sección con docente asignado pero sin bloque en el horario' as motivo"),
            ])
            ->get();

        return [
            'sin_docente'           => $sinDocente->toArray(),
            'sin_bloque_en_horario' => $sinBloqueEnHorario->toArray(),
            'total_sin_docente'     => $sinDocente->count(),
            'total_sin_bloque'      => $sinBloqueEnHorario->count(),
        ];
    }

    // ── Reporte 4: Resumen de asignaciones docentes ──────────────

    /**
     * Carga docente por período.
     *
     * total_secciones_asignadas = COUNT(DISTINCT adc.id_asignacion_docente_curso)
     *   → Cuántos cursos distintos imparte el docente
     *
     * total_bloques_horario = COUNT(dh.id_detalle_horario)
     *   → Cuántos bloques/horas ocupa en el horario (puede ser mayor
     *     si un curso tiene más de un bloque)
     *
     * @param int      $idCarrera
     * @param int      $idPeriodo
     * @param int|null $idHorario  null = resumen de asignaciones sin considerar el horario
     * @param int|null $idCoord    null = admin
     */
    public function resumenAsignaciones(
        int  $idCarrera,
        int  $idPeriodo,
        ?int $idHorario = null,
        ?int $idCoord   = null,
    ): array {
        if ($idCoord !== null) {
            $this->verificarCarreraCoordinador($idCarrera, $idCoord);
        }

        // Si se proporciona id_horario, verificar que pertenezca a la carrera y período
        if ($idHorario !== null) {
            $this->verificarHorarioPerteneceACarreraYPeriodo($idHorario, $idCarrera, $idPeriodo);
        }

        // Año del período — necesario para resolver el pensum vigente.
        // REFACTOR PENSUM: ya no se usa p.id_periodo_academico en la tabla pensum.
        $anio = (int) DB::table('periodo_academico')
            ->where('id_periodo_academico', $idPeriodo)
            ->value('anio');

        $query = DB::table('asignacion_docente_curso as adc')
            ->join('docente as d',   'adc.id_docente',  '=', 'd.id_docente')
            ->join('usuario as u',   'd.id_usuario',    '=', 'u.id_usuario')
            ->join('seccion as s',   'adc.id_seccion',  '=', 's.id_seccion')
            ->join('curso as c',     's.id_curso',      '=', 'c.id_curso')
            // Solo secciones de esta carrera (via pensum vigente del año)
            ->whereExists(function ($sub) use ($idCarrera, $anio) {
                $sub->select(DB::raw(1))
                    ->from('pensum_curso as pc')
                    ->join('pensum as p', 'pc.id_pensum', '=', 'p.id_pensum')
                    ->whereColumn('pc.id_curso', 's.id_curso')
                    ->where('p.id_carrera', $idCarrera)
                    ->where('p.estado', 'activo')
                    ->where('p.anio_inicio_vigencia', '<=', $anio)
                    ->where(function ($q) use ($anio) {
                        $q->whereNull('p.anio_fin_vigencia')
                          ->orWhere('p.anio_fin_vigencia', '>=', $anio);
                    })
                    ->where('pc.estado', 'activo');
            })
            ->where('s.id_periodo_academico', $idPeriodo)
            ->where('adc.estado', 'activo')
            ->where('s.estado', 'activo')
            ->where('d.estado', 'activo');

        // total_bloques_horario solo si se provee un horario específico
        if ($idHorario !== null) {
            $query->leftJoin('detalle_horario as dh', function ($join) use ($idHorario) {
                $join->on('dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
                     ->where('dh.id_horario', $idHorario)
                     ->where('dh.estado', 'activo');
            });

            $select = [
                'd.id_docente',
                DB::raw("CONCAT(u.nombres, ' ', u.apellidos) as nombre_docente"),
                'd.codigo_docente',
                'd.prioridad',
                DB::raw('COUNT(DISTINCT adc.id_asignacion_docente_curso) as total_secciones_asignadas'),
                DB::raw('COUNT(dh.id_detalle_horario) as total_bloques_horario'),
            ];
        } else {
            $select = [
                'd.id_docente',
                DB::raw("CONCAT(u.nombres, ' ', u.apellidos) as nombre_docente"),
                'd.codigo_docente',
                'd.prioridad',
                DB::raw('COUNT(DISTINCT adc.id_asignacion_docente_curso) as total_secciones_asignadas'),
                DB::raw('0 as total_bloques_horario'),
            ];
        }

        return $query
            ->groupBy('d.id_docente', 'u.nombres', 'u.apellidos', 'd.codigo_docente', 'd.prioridad')
            ->orderBy('d.prioridad')
            ->orderBy('u.apellidos')
            ->select($select)
            ->get()
            ->toArray();
    }

    // ── Helper compartido (replicado de HorarioConsultaService) ──

    /**
     * Verifica que el coordinador gestione la carrera.
     * Duplicado necesario porque ReporteDataService no extiende HorarioConsultaService
     * y el método original es privado.
     */
    private function verificarCarreraCoordinador(int $idCarrera, int $idUsuario): void
    {
        $coordina = DB::table('carrera')
            ->where('id_carrera', $idCarrera)
            ->where('id_usuario_coordinador', $idUsuario)
            ->exists();

        if (! $coordina) {
            abort(403, 'No tiene permisos para generar reportes de esta carrera.');
        }
    }

    /**
     * Verifica que el horario pertenezca a la carrera y período indicados.
     * Evita cruzar datos entre horarios de distintas carreras o períodos.
     * Lanza 422 si no coincide.
     */
    private function verificarHorarioPerteneceACarreraYPeriodo(
        int $idHorario,
        int $idCarrera,
        int $idPeriodo,
    ): void {
        $pertenece = DB::table('horario')
            ->where('id_horario',          $idHorario)
            ->where('id_carrera',          $idCarrera)
            ->where('id_periodo_academico', $idPeriodo)
            ->exists();

        if (! $pertenece) {
            abort(422,
                "El horario #{$idHorario} no pertenece a la carrera #{$idCarrera} "
                . "y período #{$idPeriodo} indicados."
            );
        }
    }
}
