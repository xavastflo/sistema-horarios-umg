<?php

namespace App\Services\Horario;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HorarioConsultaService — Sprint 4, Paso 1
 *
 * Responsabilidad: centralizar todas las queries de consulta de
 * horarios. El controller delega aquí; solo valida permisos y formatea
 * la respuesta HTTP.
 *
 * ── Reglas de negocio ────────────────────────────────────────────
 *
 * - Admin: puede consultar cualquier carrera y estado.
 * - Coordinador: solo carreras donde carrera.id_usuario_coordinador
 *   coincida con su id_usuario. Validado en cada método.
 * - Docente: solo sus propias clases (filtro por id_docente del
 *   usuario autenticado). Cualquier estado.
 * - Estudiante: solo horarios en estado 'publicado'. Recibe
 *   id_carrera e id_periodo_academico como parámetros porque no
 *   existe tabla estudiante_carrera en el modelo oficial.
 *
 * ── ciclo_semestre ───────────────────────────────────────────────
 *
 * Se resuelve siempre a través de:
 *   Horario.id_carrera + Horario.id_periodo_academico
 *   → Pensum (activo de esa carrera y período)
 *   → Pensum_Curso (por id_curso de la sección)
 * No se toma de ningún otro pensum del período para evitar
 * ambigüedad cuando el mismo curso existe en varias carreras.
 */
class HorarioConsultaService
{
    // ── Consulta 1: horarios por carrera y período ──────────────

    /**
     * Lista los horarios de una carrera y período.
     * Incluye estado, versión y conteo de detalles activos.
     *
     * @param int      $idCarrera
     * @param int      $idPeriodo
     * @param int|null $idUsuarioCoordinador  Si no-null, verifica que la carrera
     *                                        la coordine ese usuario.
     */
    public function porCarreraYPeriodo(
        int  $idCarrera,
        int  $idPeriodo,
        ?int $idUsuarioCoordinador = null,
    ): array {

        // Verificar pertenencia del coordinador
        if ($idUsuarioCoordinador !== null) {
            $this->verificarCarreraCoordinador($idCarrera, $idUsuarioCoordinador);
        }

        // leftJoin a jornada eliminado: el horario puede tener detalles en
        // múltiples jornadas y la jornada no se incluye en este listado.
        // La jornada se devuelve en detallesCompletos() y porDocente().
        $horarios = DB::table('horario as h')
            ->join('carrera as c',          'h.id_carrera',          '=', 'c.id_carrera')
            ->join('periodo_academico as p', 'h.id_periodo_academico', '=', 'p.id_periodo_academico')
            ->join('estado_horario as eh',  'h.id_estado_horario',   '=', 'eh.id_estado_horario')
            ->where('h.id_carrera',          $idCarrera)
            ->where('h.id_periodo_academico', $idPeriodo)
            ->select([
                'h.id_horario',
                'h.id_carrera',
                'h.id_periodo_academico',
                'h.id_estado_horario',
                'h.version_horario',
                'h.fecha_generacion',
                'h.fecha_aprobacion',
                'h.fecha_bloqueo',
                'h.observaciones',
                'c.nombre_carrera',
                'c.codigo_carrera',
                'p.nombre_periodo',
                'p.anio',
                'p.numero_periodo',
                'eh.nombre_estado',
                DB::raw('(SELECT COUNT(*) FROM detalle_horario dh
                          WHERE dh.id_horario = h.id_horario
                            AND dh.estado = \'activo\') as total_detalles'),
            ])
            ->orderByDesc('h.version_horario')
            ->get();

        return $horarios->toArray();
    }

    // ── Consulta 2: detalles completos de un horario ────────────

    /**
     * Detalles enriquecidos de un horario: curso, sección, docente,
     * día, hora, jornada, carrera y ciclo_semestre.
     *
     * ciclo_semestre se resuelve usando el pensum de la carrera
     * y período del horario (no de cualquier pensum del período).
     *
     * @param int      $idHorario
     * @param int|null $idUsuarioCoordinador  Si no-null, verifica coordinación de la carrera.
     */
    public function detallesCompletos(
        int  $idHorario,
        ?int $idUsuarioCoordinador = null,
    ): array {

        // Cargar datos del horario primero para verificar coordinador
        $horario = DB::table('horario as h')
            ->join('carrera as c',          'h.id_carrera',          '=', 'c.id_carrera')
            ->join('periodo_academico as p', 'h.id_periodo_academico', '=', 'p.id_periodo_academico')
            ->join('estado_horario as eh',   'h.id_estado_horario',   '=', 'eh.id_estado_horario')
            ->where('h.id_horario', $idHorario)
            ->select([
                'h.id_horario',
                'h.id_carrera',
                'h.id_periodo_academico',
                'h.version_horario',
                'h.fecha_generacion',
                'h.fecha_aprobacion',
                'h.fecha_bloqueo',
                'h.observaciones',
                'c.nombre_carrera',
                'c.codigo_carrera',
                'p.nombre_periodo',
                'p.anio',
                'p.numero_periodo',
                'eh.nombre_estado',
            ])
            ->first();

        if (! $horario) {
            return [];
        }

        if ($idUsuarioCoordinador !== null) {
            $this->verificarCarreraCoordinador($horario->id_carrera, $idUsuarioCoordinador);
        }

        // Obtener el id_pensum activo de esta carrera y período
        // (mismo contexto que usa ConflictValidationService::validarCicloTraslape)
        $idPensum = DB::table('pensum')
            ->where('id_carrera',          $horario->id_carrera)
            ->where('id_periodo_academico', $horario->id_periodo_academico)
            ->where('estado', 'activo')
            ->value('id_pensum');

        // Detalles activos con todos los campos requeridos
        $detalles = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('docente as d',   'adc.id_docente',  '=', 'd.id_docente')
            ->join('usuario as u',   'd.id_usuario',    '=', 'u.id_usuario')
            ->join('seccion as s',   'adc.id_seccion',  '=', 's.id_seccion')
            ->join('curso as c',     's.id_curso',      '=', 'c.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('carrera_jornada as cj', 'bh.id_carrera_jornada', '=', 'cj.id_carrera_jornada')
            ->join('jornada as j',   'cj.id_jornada',   '=', 'j.id_jornada')
            ->join('dia',            'dh.id_dia',        '=', 'dia.id_dia')
            // ciclo_semestre anclado al pensum de esta carrera y período
            ->leftJoin('pensum_curso as pc', function ($join) use ($idPensum) {
                $join->on('pc.id_curso', '=', 's.id_curso')
                     ->where('pc.id_pensum', $idPensum)
                     ->where('pc.estado', 'activo');
            })
            ->where('dh.id_horario', $idHorario)
            ->where('dh.estado', 'activo')
            ->orderBy('dia.orden_semana')
            ->orderBy('bh.hora_inicio')
            ->select([
                'dh.id_detalle_horario',
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                's.id_seccion',
                DB::raw("CONCAT(u.nombres, ' ', u.apellidos) as nombre_docente"),
                'd.codigo_docente',
                'dia.nombre_dia',
                'dia.orden_semana',
                'bh.hora_inicio',
                'bh.hora_fin',
                'bh.duracion_minutos',
                'j.nombre_jornada',
                'pc.ciclo_semestre',    // null si el curso no está en el pensum de esta carrera
            ])
            ->get();

        return [
            'horario'  => $horario,
            'detalles' => $detalles->toArray(),
            'total'    => $detalles->count(),
        ];
    }

    // ── Consulta 3: horario del docente autenticado ─────────────

    /**
     * Clases asignadas al docente autenticado, en todos sus horarios
     * activos (cualquier estado editable o publicado).
     *
     * Agrupa por horario para que el docente vea en qué carrera y
     * período tiene clases.
     *
     * @param int $idDocente  Obtenido del usuario autenticado — nunca del request.
     */
    public function porDocente(int $idDocente): array
    {
        $clases = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('horario as h',      'dh.id_horario',      '=', 'h.id_horario')
            ->join('carrera as ca',     'h.id_carrera',       '=', 'ca.id_carrera')
            ->join('periodo_academico as p', 'h.id_periodo_academico', '=', 'p.id_periodo_academico')
            ->join('estado_horario as eh',  'h.id_estado_horario', '=', 'eh.id_estado_horario')
            ->join('seccion as s',      'adc.id_seccion',     '=', 's.id_seccion')
            ->join('curso as c',        's.id_curso',         '=', 'c.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('carrera_jornada as cj', 'bh.id_carrera_jornada', '=', 'cj.id_carrera_jornada')
            ->join('jornada as j',      'cj.id_jornada',     '=', 'j.id_jornada')
            ->join('dia',               'dh.id_dia',          '=', 'dia.id_dia')
            ->where('adc.id_docente', $idDocente)
            ->where('dh.estado', 'activo')
            ->where('adc.estado', 'activo')
            ->orderBy('p.anio',     'desc')
            ->orderBy('p.numero_periodo', 'desc')
            ->orderBy('dia.orden_semana')
            ->orderBy('bh.hora_inicio')
            ->select([
                'dh.id_detalle_horario',
                'h.id_horario',
                'h.version_horario',
                'eh.nombre_estado as estado_horario',
                'ca.nombre_carrera',
                'ca.codigo_carrera',
                'p.nombre_periodo',
                'p.anio',
                'p.numero_periodo',
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                'dia.nombre_dia',
                'dia.orden_semana',
                'bh.hora_inicio',
                'bh.hora_fin',
                'bh.duracion_minutos',
                'j.nombre_jornada',
            ])
            ->get();

        return $clases->toArray();
    }

    // ── Consulta 4: horario publicado para estudiante ───────────

    /**
     * Devuelve solo detalles de horarios en estado 'publicado'
     * para una carrera y período indicados por el estudiante.
     *
     * No existe tabla estudiante_carrera en el modelo oficial,
     * por lo que el estudiante pasa id_carrera e id_periodo_academico
     * como query params. El filtro de estado 'publicado' se aplica
     * en la query (no en PHP) para eficiencia.
     *
     * @param int $idCarrera
     * @param int $idPeriodo
     */
    public function publicadoPorCarreraYPeriodo(
        int $idCarrera,
        int $idPeriodo,
    ): array {

        // Verificar que existe el horario publicado para esa carrera-período
        $idHorario = DB::table('horario as h')
            ->join('estado_horario as eh', 'h.id_estado_horario', '=', 'eh.id_estado_horario')
            ->where('h.id_carrera',           $idCarrera)
            ->where('h.id_periodo_academico', $idPeriodo)
            ->where('eh.nombre_estado',       'publicado')
            ->value('h.id_horario');

        if (! $idHorario) {
            return [
                'publicado'   => false,
                'mensaje'     => 'No existe horario publicado para la carrera y período indicados.',
                'detalles'    => [],
            ];
        }

        // Obtener pensum activo de esa carrera y período (para ciclo_semestre)
        $idPensum = DB::table('pensum')
            ->where('id_carrera',           $idCarrera)
            ->where('id_periodo_academico', $idPeriodo)
            ->where('estado', 'activo')
            ->value('id_pensum');

        $detalles = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('docente as d',   'adc.id_docente',  '=', 'd.id_docente')
            ->join('usuario as u',   'd.id_usuario',    '=', 'u.id_usuario')
            ->join('seccion as s',   'adc.id_seccion',  '=', 's.id_seccion')
            ->join('curso as c',     's.id_curso',      '=', 'c.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('carrera_jornada as cj', 'bh.id_carrera_jornada', '=', 'cj.id_carrera_jornada')
            ->join('jornada as j',   'cj.id_jornada',  '=', 'j.id_jornada')
            ->join('dia',            'dh.id_dia',       '=', 'dia.id_dia')
            // ciclo_semestre anclado al pensum correcto de esta carrera/período
            ->leftJoin('pensum_curso as pc', function ($join) use ($idPensum) {
                $join->on('pc.id_curso', '=', 's.id_curso')
                     ->where('pc.id_pensum', $idPensum)
                     ->where('pc.estado', 'activo');
            })
            ->where('dh.id_horario', $idHorario)
            ->where('dh.estado', 'activo')
            ->orderBy('dia.orden_semana')
            ->orderBy('bh.hora_inicio')
            ->select([
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                DB::raw("CONCAT(u.nombres, ' ', u.apellidos) as nombre_docente"),
                'dia.nombre_dia',
                'dia.orden_semana',
                'bh.hora_inicio',
                'bh.hora_fin',
                'bh.duracion_minutos',
                'j.nombre_jornada',
                'pc.ciclo_semestre',
            ])
            ->get();

        return [
            'publicado'  => true,
            'id_horario' => $idHorario,
            'detalles'   => $detalles->toArray(),
            'total'      => $detalles->count(),
        ];
    }

    // ── Consulta 5: lista de horarios para admin/coord ──────────

    /**
     * Lista todos los horarios accesibles para el usuario.
     * Admin: todos.
     * Coordinador: solo sus carreras (carrera.id_usuario_coordinador = id_usuario).
     *
     * Filtros opcionales: id_carrera, id_periodo_academico, nombre_estado.
     *
     * @param int|null $idUsuarioCoordinador  null = admin (sin restricción de carrera)
     * @param int|null $idCarreraFiltro
     * @param int|null $idPeriodoFiltro
     * @param string|null $estadoFiltro
     */
    public function listar(
        ?int    $idUsuarioCoordinador = null,
        ?int    $idCarreraFiltro      = null,
        ?int    $idPeriodoFiltro      = null,
        ?string $estadoFiltro         = null,
    ): array {

        $query = DB::table('horario as h')
            ->join('carrera as c',          'h.id_carrera',          '=', 'c.id_carrera')
            ->join('periodo_academico as p', 'h.id_periodo_academico', '=', 'p.id_periodo_academico')
            ->join('estado_horario as eh',  'h.id_estado_horario',   '=', 'eh.id_estado_horario');

        // Restricción de coordinador — aplica antes que cualquier otro filtro
        if ($idUsuarioCoordinador !== null) {
            $query->where('c.id_usuario_coordinador', $idUsuarioCoordinador);
        }

        // Filtros opcionales
        if ($idCarreraFiltro !== null) {
            $query->where('h.id_carrera', $idCarreraFiltro);
        }
        if ($idPeriodoFiltro !== null) {
            $query->where('h.id_periodo_academico', $idPeriodoFiltro);
        }
        if ($estadoFiltro !== null) {
            $query->where('eh.nombre_estado', $estadoFiltro);
        }

        return $query
            ->select([
                'h.id_horario',
                'h.id_carrera',
                'h.id_periodo_academico',
                'h.version_horario',
                'h.fecha_generacion',
                'h.fecha_aprobacion',
                'h.fecha_bloqueo',
                'h.observaciones',
                'c.nombre_carrera',
                'c.codigo_carrera',
                'p.nombre_periodo',
                'p.anio',
                'p.numero_periodo',
                'eh.nombre_estado',
                DB::raw('(SELECT COUNT(*) FROM detalle_horario dh
                          WHERE dh.id_horario = h.id_horario
                            AND dh.estado = \'activo\') as total_detalles'),
            ])
            ->orderByDesc('p.anio')
            ->orderByDesc('p.numero_periodo')
            ->orderBy('c.nombre_carrera')
            ->get()
            ->toArray();
    }

    // ── Helper compartido ───────────────────────────────────────

    /**
     * Verifica que el coordinador gestione esa carrera.
     * Lanza excepción HTTP 403 si no la coordina.
     *
     * @throws \Illuminate\Http\Exceptions\HttpResponseException
     */
    private function verificarCarreraCoordinador(int $idCarrera, int $idUsuario): void
    {
        $coordina = DB::table('carrera')
            ->where('id_carrera', $idCarrera)
            ->where('id_usuario_coordinador', $idUsuario)
            ->exists();

        if (! $coordina) {
            abort(403, 'No tiene permisos para consultar esta carrera.');
        }
    }
}
