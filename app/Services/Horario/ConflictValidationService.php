<?php

namespace App\Services\Horario;

use App\Models\DetalleHorario;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;

/**
 * ConflictValidationService
 *
 * Validaciones en orden de menor a mayor costo en BD:
 *   1. Fecha límite de edición    → sin query
 *   2. Estado del horario         → 0-1 queries
 *   3. Disponibilidad docente     → 1 query, traslape por dia+hora
 *   4. Docente ocupado            → 1 query con JOIN, traslape por dia+hora
 *   5. Traslape de ciclo          → 1-2 queries con JOIN
 *   6. Duplicidad activa exacta   → 1 query simple por idx_detalle_asignacion_bloque
 *
 * DECISIONES ARQUITECTÓNICAS:
 *
 *   Validación 4 — validarDocenteOcupado:
 *     Ya no excluye el id_horario completo. El horario maestro contiene
 *     detalles de múltiples jornadas/ciclos; excluirlo todo ignoraría choques
 *     reales del docente contra detalles activos de otras jornadas del mismo
 *     horario. Si se necesita excluir un detalle específico (reubicación),
 *     se pasa $excluirDetalleId.
 *
 *   Validación 6 — validarBloqueEnHorario:
 *     No bloquea globalmente el uso del mismo id_bloque_horario en el mismo
 *     horario (paralelismo entre ciclos). Solo impide duplicar activamente
 *     la MISMA asignación en el MISMO bloque del MISMO horario.
 */
class ConflictValidationService
{
    // ── Punto de entrada principal ──────────────────────────────

    public function validarTodo(
        int              $idDocente,
        int              $idBloque,
        int              $idHorario,
        int              $idSeccion,
        PeriodoAcademico $periodo,
        Horario          $horario,
        int              $idAsignacionDocenteCurso = 0,
    ): ValidacionResultado {

        $r1 = $this->validarFechaLimite($periodo);
        if ($r1->tieneConflictos()) return $r1;

        $r2 = $this->validarEstadoHorario($horario);
        if ($r2->tieneConflictos()) return $r2;

        $r3 = $this->validarDisponibilidadDocente($idDocente, $idBloque);
        $r4 = $this->validarDocenteOcupado($idDocente, $idBloque);
        $r5 = $this->validarCicloTraslape($idSeccion, $idBloque, $idHorario, $horario);
        $r6 = $this->validarBloqueEnHorario($idAsignacionDocenteCurso, $idBloque, $idHorario);

        return $r3->merge($r4)->merge($r5)->merge($r6);
    }

    // ── Validación 1: Fecha límite ───────────────────────────────

    public function validarFechaLimite(PeriodoAcademico $periodo): ValidacionResultado
    {
        if ($periodo->estaEnPlazoEdicion()) {
            return ValidacionResultado::sinConflictos();
        }

        return ValidacionResultado::conConflictos([
            ConflictoItem::fechaLimiteVencida(
                $periodo->fecha_limite_edicion_horarios
            ),
        ]);
    }

    // ── Validación 2: Estado del horario ────────────────────────

    public function validarEstadoHorario(Horario $horario): ValidacionResultado
    {
        $estadosEditables = ['borrador', 'generado'];

        $nombreEstado = $horario->estadoHorario?->nombre_estado
            ?? DB::table('estado_horario')
                ->where('id_estado_horario', $horario->id_estado_horario)
                ->value('nombre_estado');

        if (in_array($nombreEstado, $estadosEditables, true)) {
            return ValidacionResultado::sinConflictos();
        }

        return ValidacionResultado::conConflictos([
            ConflictoItem::horarioNoEditable($nombreEstado ?? 'desconocido'),
        ]);
    }

    // ── Validación 3: Disponibilidad docente ────────────────────

    /**
     * Compara por (id_dia + traslape de hora) para detectar conflictos
     * entre bloques de distintas carreras con el mismo día y franja.
     */
    public function validarDisponibilidadDocente(
        int $idDocente,
        int $idBloque,
    ): ValidacionResultado {

        $bloqueCandidato = DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_bloque_horario', $idBloque)
            ->select(['bh.id_dia', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueCandidato) return ValidacionResultado::sinConflictos();

        $bloqueo = DB::table('disponibilidad_docente as dd')
            ->join('bloque_horario as bh', 'dd.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('dd.id_docente', $idDocente)
            ->where('dd.estado', 'activo')
            ->where('bh.id_dia', $bloqueCandidato->id_dia)
            ->where('bh.hora_inicio', '<', $bloqueCandidato->hora_fin)
            ->where('bh.hora_fin',    '>', $bloqueCandidato->hora_inicio)
            ->select(['bh.id_bloque_horario', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueo) return ValidacionResultado::sinConflictos();

        return ValidacionResultado::conConflictos([
            ConflictoItem::docenteNoDisponible(
                idDocente:  $idDocente,
                idBloque:   $idBloque,
                horaInicio: $bloqueCandidato->hora_inicio,
                horaFin:    $bloqueCandidato->hora_fin,
                nombreDia:  $bloqueCandidato->nombre_dia,
            ),
        ]);
    }

    // ── Validación 4: Docente ocupado ───────────────────────────

    /**
     * Verifica que el docente no tenga ya una clase que traslape con el
     * bloque candidato en cualquier detalle activo.
     *
     * CORRECCIÓN: ya NO excluye todo el id_horario.
     *   El horario maestro contiene detalles de múltiples jornadas y ciclos.
     *   Excluir todo el horario ignoraría choques reales del mismo docente
     *   contra detalles activos de otras jornadas dentro del mismo horario.
     *
     *   Si se necesita excluir un detalle específico (reubicación manual),
     *   se pasa $excluirDetalleId.
     *
     * @param int      $idDocente
     * @param int      $idBloque
     * @param int|null $excluirDetalleId  Excluir un detalle_horario específico (reubicación)
     */
    public function validarDocenteOcupado(
        int  $idDocente,
        int  $idBloque,
        ?int $excluirDetalleId = null,
    ): ValidacionResultado {

        $bloqueCandidato = DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_bloque_horario', $idBloque)
            ->select(['bh.id_dia', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueCandidato) return ValidacionResultado::sinConflictos();

        $query = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('horario as h', 'dh.id_horario', '=', 'h.id_horario')
            ->join('carrera as c', 'h.id_carrera', '=', 'c.id_carrera')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('adc.id_docente', $idDocente)
            ->where('dh.estado', 'activo')          // solo detalles activos
            ->where('adc.estado', 'activo')
            ->whereIn('h.id_estado_horario', function ($sub) {
                $sub->select('id_estado_horario')
                    ->from('estado_horario')
                    ->whereIn('nombre_estado', [
                        'borrador', 'generado', 'aprobado', 'bloqueado', 'publicado',
                    ]);
            })
            ->where('bh.id_dia', $bloqueCandidato->id_dia)
            ->where('bh.hora_inicio', '<', $bloqueCandidato->hora_fin)
            ->where('bh.hora_fin',    '>', $bloqueCandidato->hora_inicio)
            ->select([
                'dh.id_horario',
                'c.nombre_carrera',
                'bh.hora_inicio',
                'bh.hora_fin',
                'dia.nombre_dia',
            ]);

        // Solo excluir el detalle específico que se está reubicando, si aplica
        if ($excluirDetalleId !== null) {
            $query->where('dh.id_detalle_horario', '!=', $excluirDetalleId);
        }

        $conflicto = $query->first();

        if (! $conflicto) return ValidacionResultado::sinConflictos();

        return ValidacionResultado::conConflictos([
            ConflictoItem::docenteOcupado(
                idDocente:              $idDocente,
                idBloque:               $idBloque,
                horaInicio:             $bloqueCandidato->hora_inicio,
                horaFin:                $bloqueCandidato->hora_fin,
                nombreDia:              $bloqueCandidato->nombre_dia,
                idHorarioConflicto:     $conflicto->id_horario,
                nombreCarreraConflicto: $conflicto->nombre_carrera,
            ),
        ]);
    }

    // ── Validación 5: Traslape de ciclo/semestre ────────────────

    /**
     * Verifica que no existan dos cursos del mismo ciclo_semestre asignados
     * a una franja traslapante dentro del mismo id_horario.
     *
     * Ancla al pensum vigente via Horario.id_carrera + año del período académico.
     */
    public function validarCicloTraslape(
        int     $idSeccion,
        int     $idBloque,
        int     $idHorario,
        Horario $horario,
    ): ValidacionResultado {

        $idCarrera = $horario->id_carrera;
        $idPeriodo = $horario->id_periodo_academico;

        $anioPeriodo = (int) DB::table('periodo_academico')
            ->where('id_periodo_academico', $idPeriodo)
            ->value('anio');

        $idPensum = DB::table('pensum')
            ->where('id_carrera', $idCarrera)
            ->where('estado', 'activo')
            ->where('anio_inicio_vigencia', '<=', $anioPeriodo)
            ->where(function ($q) use ($anioPeriodo) {
                $q->whereNull('anio_fin_vigencia')
                  ->orWhere('anio_fin_vigencia', '>=', $anioPeriodo);
            })
            ->orderByDesc('anio_inicio_vigencia')
            ->value('id_pensum');

        if ($idPensum === null) return ValidacionResultado::sinConflictos();

        $cicloNuevo = DB::table('pensum_curso as pc')
            ->join('seccion as s', 'pc.id_curso', '=', 's.id_curso')
            ->where('s.id_seccion', $idSeccion)
            ->where('pc.id_pensum', $idPensum)
            ->where('pc.estado', 'activo')
            ->value('pc.ciclo_semestre');

        if ($cicloNuevo === null) return ValidacionResultado::sinConflictos();

        $bloqueCandidato = DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_bloque_horario', $idBloque)
            ->select(['bh.id_dia', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueCandidato) return ValidacionResultado::sinConflictos();

        $conflicto = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->join('pensum_curso as pc', function ($join) use ($idPensum) {
                $join->on('pc.id_curso', '=', 's.id_curso')
                     ->where('pc.id_pensum', $idPensum)
                     ->where('pc.estado', 'activo');
            })
            ->join('curso as cur', 's.id_curso', '=', 'cur.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('dh.id_horario', $idHorario)
            ->where('dh.estado', 'activo')
            ->where('adc.estado', 'activo')
            ->where('pc.ciclo_semestre', $cicloNuevo)
            ->where('s.id_seccion', '!=', $idSeccion)
            ->where('bh.id_dia', $bloqueCandidato->id_dia)
            ->where('bh.hora_inicio', '<', $bloqueCandidato->hora_fin)
            ->where('bh.hora_fin',    '>', $bloqueCandidato->hora_inicio)
            ->select([
                'cur.nombre_curso',
                'bh.hora_inicio',
                'bh.hora_fin',
                'dia.nombre_dia',
                'pc.ciclo_semestre',
            ])
            ->first();

        if (! $conflicto) return ValidacionResultado::sinConflictos();

        return ValidacionResultado::conConflictos([
            ConflictoItem::cicloTraslape(
                cicloSemestre:        $conflicto->ciclo_semestre,
                idBloque:             $idBloque,
                horaInicio:           $bloqueCandidato->hora_inicio,
                horaFin:              $bloqueCandidato->hora_fin,
                nombreDia:            $bloqueCandidato->nombre_dia,
                nombreCursoConflicto: $conflicto->nombre_curso,
            ),
        ]);
    }

    // ── Validación 6: Duplicidad activa de asignación en bloque ─

    /**
     * Impide que la MISMA asignación quede registrada activamente dos veces
     * en el mismo bloque del mismo horario.
     *
     * NO bloquea globalmente el uso del mismo id_bloque_horario:
     *   → Ciclos distintos con docentes distintos pueden usar el mismo bloque.
     *   → Registros inactivos (regeneración) no bloquean una nueva inserción.
     *
     * Filtra siempre estado = 'activo' para ignorar registros inactivados.
     *
     * @param int      $idAsignacionDocenteCurso  La asignación a verificar
     * @param int      $idBloque                  Bloque candidato
     * @param int      $idHorario                 Horario donde se insertará
     * @param int|null $excluirDetalle            ID de detalle a excluir (reubicación)
     */
    public function validarBloqueEnHorario(
        int  $idAsignacionDocenteCurso,
        int  $idBloque,
        int  $idHorario,
        ?int $excluirDetalle = null,
    ): ValidacionResultado {

        $query = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('dh.id_horario',                 $idHorario)
            ->where('dh.id_bloque_horario',           $idBloque)
            ->where('dh.id_asignacion_docente_curso', $idAsignacionDocenteCurso)
            ->where('dh.estado', 'activo')           // ignorar registros inactivos
            ->select([
                'dh.id_detalle_horario',
                'c.nombre_curso',
                'bh.hora_inicio',
                'bh.hora_fin',
                'dia.nombre_dia',
                's.numero_seccion',
            ]);

        if ($excluirDetalle !== null) {
            $query->where('dh.id_detalle_horario', '!=', $excluirDetalle);
        }

        $conflicto = $query->first();

        if (! $conflicto) return ValidacionResultado::sinConflictos();

        return ValidacionResultado::conConflictos([
            ConflictoItem::bloqueOcupadoEnHorario(
                idBloque:               $idBloque,
                horaInicio:             $conflicto->hora_inicio,
                horaFin:                $conflicto->hora_fin,
                nombreDia:              $conflicto->nombre_dia,
                nombreSeccionConflicto: "{$conflicto->nombre_curso} — Sec. {$conflicto->numero_seccion}",
            ),
        ]);
    }

    // ── Edición manual ───────────────────────────────────────────

    public function validarParaEdicionManual(
        int              $idDocente,
        int              $idBloque,
        int              $idHorario,
        int              $idSeccion,
        PeriodoAcademico $periodo,
        Horario          $horario,
        ?int             $excluirDetalle           = null,
        int              $idAsignacionDocenteCurso = 0,
    ): ValidacionResultado {

        $r1 = $this->validarFechaLimite($periodo);
        if ($r1->tieneConflictos()) return $r1;

        $r2 = $this->validarEstadoHorario($horario);
        if ($r2->tieneConflictos()) return $r2;

        $r3 = $this->validarDisponibilidadDocente($idDocente, $idBloque);
        $r4 = $this->validarDocenteOcupado($idDocente, $idBloque, $excluirDetalle);
        $r5 = $this->validarCicloTraslape($idSeccion, $idBloque, $idHorario, $horario);
        $r6 = $this->validarBloqueEnHorario($idAsignacionDocenteCurso, $idBloque, $idHorario, $excluirDetalle);

        return $r3->merge($r4)->merge($r5)->merge($r6);
    }
}
