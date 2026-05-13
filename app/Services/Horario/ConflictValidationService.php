<?php

namespace App\Services\Horario;

use App\Models\DetalleHorario;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use Illuminate\Support\Facades\DB;

/**
 * ConflictValidationService
 *
 * Responsabilidad única: validar si una asignación docente-bloque
 * es compatible con todas las restricciones del sistema.
 *
 * Las validaciones están ordenadas de menor a mayor costo en BD:
 *   1. Fecha límite de edición    → atributo en memoria, sin query
 *   2. Estado del horario         → 0-1 queries
 *   3. Disponibilidad docente     → 1 query, traslape por dia+hora
 *   4. Docente ocupado globalmente→ 1 query con JOIN, traslape por dia+hora
 *   5. Traslape de ciclo          → 1-2 queries con JOIN
 *   6. Bloque ocupado en horario  → 1 query simple (UNIQUE index)
 *
 * CORRECCIÓN CRÍTICA (Paso 2 — revisión):
 *   Las validaciones 3 y 4 ya NO comparan por id_bloque_horario.
 *   Comparan por (id_dia + traslape de hora_inicio/hora_fin).
 *   Esto detecta conflictos entre carreras distintas que tienen
 *   bloques con diferente ID pero en el mismo día y franja horaria.
 *
 *   Ejemplo resuelto:
 *     Carrera A: bloque ID 10, martes 18:00-19:30
 *     Carrera B: bloque ID 25, martes 18:00-19:30
 *     → Antes: no detectaba conflicto (IDs distintos)
 *     → Ahora: detecta traslape por dia+hora ✓
 *
 * ESTADOS DE HORARIO OCUPADOS:
 *   borrador, generado, aprobado, bloqueado, publicado
 *   (todos los estados del ciclo de vida — un horario bloqueado
 *   sigue siendo un compromiso real para el docente)
 */
class ConflictValidationService
{
    // ── Punto de entrada principal ──────────────────────────────

    /**
     * Ejecuta todas las validaciones en orden y devuelve el resultado
     * consolidado. Se detiene en el primer conflicto de tipo "bloqueo
     * total" (fecha límite, estado horario) para evitar queries
     * innecesarias. Las validaciones de conflicto de datos continúan
     * todas para dar información completa al coordinador.
     *
     * @param int              $idDocente  Docente a asignar
     * @param int              $idBloque   Bloque horario candidato
     * @param int              $idHorario  Horario donde se quiere insertar
     * @param int              $idSeccion  Sección que se va a asignar
     * @param PeriodoAcademico $periodo    Modelo hidratado (validación de fecha límite)
     * @param Horario          $horario    Modelo obligatorio — necesario para validar
     *                                     estado editable y anclar cicloTraslape al
     *                                     pensum correcto (id_carrera + id_periodo).
     *                                     No puede ser null: validarCicloTraslape()
     *                                     requiere Horario hidratado sin excepción.
     */
    public function validarTodo(
        int              $idDocente,
        int              $idBloque,
        int              $idHorario,
        int              $idSeccion,
        PeriodoAcademico $periodo,
        Horario          $horario,           // obligatorio — ya no nullable
    ): ValidacionResultado {

        // ── 1. Fecha límite (fallo rápido — sin queries) ────────
        $r1 = $this->validarFechaLimite($periodo);
        if ($r1->tieneConflictos()) {
            return $r1;
        }

        // ── 2. Estado del horario (fallo rápido — sin queries) ──
        $r2 = $this->validarEstadoHorario($horario);
        if ($r2->tieneConflictos()) {
            return $r2;
        }

        // ── 3-6. Validaciones de datos — ejecutar todas para dar
        //         información completa al coordinador
        $r3 = $this->validarDisponibilidadDocente($idDocente, $idBloque);
        $r4 = $this->validarDocenteOcupado($idDocente, $idBloque, $idHorario);
        $r5 = $this->validarCicloTraslape($idSeccion, $idBloque, $idHorario, $horario);
        $r6 = $this->validarBloqueEnHorario($idBloque, $idHorario);

        return $r3->merge($r4)->merge($r5)->merge($r6);
    }

    // ── Validación 1: Fecha límite de edición ───────────────────

    /**
     * Verifica que el período académico aún permite editar horarios.
     * Recibe el modelo PeriodoAcademico ya hidratado — 0 queries.
     *
     * Si fecha_limite_edicion_horarios es NULL, no hay límite configurado
     * y la edición siempre está permitida.
     */
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

    /**
     * Verifica que el horario sea editable en cuanto a su CONTENIDO
     * (clases asignadas, bloques). Solo aplica para:
     *   - Edición manual de un detalle de horario (coordinador)
     *   - Algoritmo de generación automática
     *
     * Estados editables (contenido): borrador, generado
     * Estados NO editables (contenido): aprobado, bloqueado, publicado
     *
     * IMPORTANTE — Separación de responsabilidades:
     * Este método NO controla las transiciones administrativas de estado.
     * Las siguientes acciones tienen su propio flujo y NO pasan por aquí:
     *   - Administrador aprueba un horario (borrador/generado → aprobado)
     *   - Administrador bloquea un horario (aprobado → bloqueado)
     *   - Administrador publica un horario (aprobado/bloqueado → publicado)
     * Esas transiciones serán validadas por un HorarioStateService separado
     * en el Paso 6 (Persistencia Final), con su propia máquina de estados.
     */
    public function validarEstadoHorario(Horario $horario): ValidacionResultado
    {
        $estadosEditables = ['borrador', 'generado'];

        // Cargar el nombre del estado si no está en memoria
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
     * Verifica que el docente no haya marcado como no disponible
     * ningún bloque que traslape con el bloque candidato.
     *
     * REGLA: Si existe un registro activo → NO disponible.
     *        Si no existe → disponible.
     *
     * CORRECCIÓN CRÍTICA:
     *   Compara por (id_dia + traslape de hora) en lugar de id_bloque_horario.
     *   El docente puede haber registrado no-disponibilidad en un bloque de
     *   su carrera (ej. ID 25) que tiene el mismo día y hora que el bloque
     *   candidato de otra carrera (ej. ID 10). Ambos deben quedar bloqueados.
     *
     *   Condición de traslape:
     *     bh_existente.id_dia = bh_candidato.id_dia
     *     AND bh_existente.hora_inicio < bh_candidato.hora_fin
     *     AND bh_existente.hora_fin    > bh_candidato.hora_inicio
     */
    public function validarDisponibilidadDocente(
        int $idDocente,
        int $idBloque,
    ): ValidacionResultado {

        // Obtener datos del bloque candidato para la comparación de traslape
        $bloqueCandidato = DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_bloque_horario', $idBloque)
            ->select(['bh.id_dia', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueCandidato) {
            return ValidacionResultado::sinConflictos();
        }

        // Buscar cualquier bloque bloqueado por el docente que traslape con el candidato
        $bloqueo = DB::table('disponibilidad_docente as dd')
            ->join('bloque_horario as bh', 'dd.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('dd.id_docente', $idDocente)
            ->where('dd.estado', 'activo')
            // Mismo día
            ->where('bh.id_dia', $bloqueCandidato->id_dia)
            // Traslape de horario:
            //   bloque_existente.hora_inicio < candidato.hora_fin
            //   bloque_existente.hora_fin    > candidato.hora_inicio
            ->where('bh.hora_inicio', '<', $bloqueCandidato->hora_fin)
            ->where('bh.hora_fin',    '>', $bloqueCandidato->hora_inicio)
            ->select([
                'bh.id_bloque_horario',
                'bh.hora_inicio',
                'bh.hora_fin',
                'dia.nombre_dia',
            ])
            ->first();

        if (! $bloqueo) {
            return ValidacionResultado::sinConflictos();
        }

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

    // ── Validación 4: Docente ocupado globalmente ───────────────

    /**
     * Verifica que el docente no tenga ya una clase que traslape con
     * el bloque candidato en cualquier horario activo.
     *
     * CORRECCIÓN CRÍTICA:
     *   Compara por (id_dia + traslape de hora) en lugar de id_bloque_horario.
     *
     *   Ejemplo resuelto:
     *     Horario H1 (Carrera A): docente tiene clase en bloque ID 25 (martes 18:00-19:30)
     *     Candidato para Carrera B: bloque ID 10 (martes 18:00-19:30)
     *     → Antes: WHERE dh.id_bloque_horario = 10 → no encontraba conflicto
     *     → Ahora: compara id_dia + traslape → detecta conflicto ✓
     *
     * ESTADO 'bloqueado' INCLUIDO:
     *   Un horario bloqueado es un compromiso real del docente.
     *   Se incluye junto con borrador, generado, aprobado y publicado.
     *
     * @param int $idHorarioActual  Se excluye para permitir reubicar dentro
     *                              del mismo horario sin auto-conflictar.
     */
    public function validarDocenteOcupado(
        int $idDocente,
        int $idBloque,
        int $idHorarioActual,
    ): ValidacionResultado {

        // Obtener datos del bloque candidato para la comparación de traslape
        $bloqueCandidato = DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_bloque_horario', $idBloque)
            ->select(['bh.id_dia', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueCandidato) {
            return ValidacionResultado::sinConflictos();
        }

        $conflicto = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('horario as h', 'dh.id_horario', '=', 'h.id_horario')
            ->join('carrera as c', 'h.id_carrera', '=', 'c.id_carrera')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('adc.id_docente', $idDocente)
            ->where('dh.estado', 'activo')
            ->where('adc.estado', 'activo')
            // Excluir el horario actual (permite reubicar dentro del mismo horario)
            ->where('dh.id_horario', '!=', $idHorarioActual)
            // Todos los estados del ciclo de vida — incluido 'bloqueado'
            ->whereIn('h.id_estado_horario', function ($sub) {
                $sub->select('id_estado_horario')
                    ->from('estado_horario')
                    ->whereIn('nombre_estado', [
                        'borrador',
                        'generado',
                        'aprobado',
                        'bloqueado',   // ← CORRECCIÓN: incluido
                        'publicado',
                    ]);
            })
            // Mismo día
            ->where('bh.id_dia', $bloqueCandidato->id_dia)
            // Traslape de horario
            ->where('bh.hora_inicio', '<', $bloqueCandidato->hora_fin)
            ->where('bh.hora_fin',    '>', $bloqueCandidato->hora_inicio)
            ->select([
                'dh.id_horario',
                'c.nombre_carrera',
                'bh.hora_inicio',
                'bh.hora_fin',
                'dia.nombre_dia',
            ])
            ->first();

        if (! $conflicto) {
            return ValidacionResultado::sinConflictos();
        }

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
     * Verifica que no existan dos cursos del mismo ciclo_semestre
     * asignados al mismo bloque dentro de un mismo horario.
     *
     * CORRECCIÓN (revisión Paso 2):
     * Anteriormente filtraba por id_periodo_academico sin anclar a la carrera
     * del horario. Si el mismo curso existe en pensums de carreras distintas
     * dentro del mismo período, podía tomar el ciclo de la carrera equivocada.
     *
     * Ahora el contexto se extrae del modelo Horario:
     *   - Horario.id_carrera          → identifica la carrera específica
     *   - Horario.id_periodo_academico → identifica el período
     * Con ambos se localiza el Pensum activo correcto y se busca el
     * Pensum_Curso que pertenece a ese pensum específico, no a cualquier
     * pensum del período.
     *
     * La búsqueda de conflictos existentes también ancla al mismo pensum
     * para comparar únicamente contra cursos de la misma carrera.
     *
     * @param int     $idSeccion  La sección que se quiere asignar
     * @param int     $idBloque   El bloque candidato
     * @param int     $idHorario  El horario donde se insertará
     * @param Horario $horario    Modelo hidratado — provee id_carrera + id_periodo_academico
     */
    public function validarCicloTraslape(
        int     $idSeccion,
        int     $idBloque,
        int     $idHorario,
        Horario $horario,
    ): ValidacionResultado {

        $idCarrera = $horario->id_carrera;
        $idPeriodo = $horario->id_periodo_academico;

        // ── Paso A: Localizar el pensum activo de esta carrera y período ─
        // Se toma el primero activo. Si hay ambigüedad (más de un pensum
        // activo para la misma carrera-período), el coordinador debe
        // mantener solo uno activo antes de generar el horario.
        $idPensum = DB::table('pensum')
            ->where('id_carrera', $idCarrera)
            ->where('id_periodo_academico', $idPeriodo)
            ->where('estado', 'activo')
            ->value('id_pensum');

        // Si no existe pensum activo para esta carrera-período:
        // no se puede validar traslape → se permite (no bloquear lo no verificable)
        if ($idPensum === null) {
            return ValidacionResultado::sinConflictos();
        }

        // ── Paso B: Obtener el ciclo del curso de la sección candidata ───
        // Anclar a id_pensum específico — NO a cualquier pensum del período
        $cicloNuevo = DB::table('pensum_curso as pc')
            ->join('seccion as s', 'pc.id_curso', '=', 's.id_curso')
            ->where('s.id_seccion', $idSeccion)
            ->where('pc.id_pensum', $idPensum)
            ->where('pc.estado', 'activo')
            ->value('pc.ciclo_semestre');

        // Sin ciclo definido en este pensum → se permite
        if ($cicloNuevo === null) {
            return ValidacionResultado::sinConflictos();
        }

        // ── Paso C: Buscar conflicto — otro curso del mismo ciclo que
        //            traslape en tiempo con el bloque candidato,
        //            dentro del mismo horario,
        //            comparando solo contra cursos del mismo pensum ────────
        //
        // CORRECCIÓN (ajuste final Paso 2):
        // Antes se comparaba por dh.id_bloque_horario = $idBloque (ID exacto).
        // Ahora se compara por traslape real de tiempo:
        //   bh.id_dia = candidato.id_dia
        //   bh.hora_inicio < candidato.hora_fin
        //   bh.hora_fin    > candidato.hora_inicio
        // Esto detecta que el ciclo 3 ya tiene clase en martes 18:00-19:30
        // aunque sea en un bloque con ID distinto al candidato.

        // Obtener datos del bloque candidato para la comparación
        $bloqueCandidato = DB::table('bloque_horario as bh')
            ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
            ->where('bh.id_bloque_horario', $idBloque)
            ->select(['bh.id_dia', 'bh.hora_inicio', 'bh.hora_fin', 'dia.nombre_dia'])
            ->first();

        if (! $bloqueCandidato) {
            return ValidacionResultado::sinConflictos();
        }

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
            // Traslape real de tiempo — mismo día, horas solapadas
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

        if (! $conflicto) {
            return ValidacionResultado::sinConflictos();
        }

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


    // ── Validación 6: Bloque ya ocupado en este horario ─────────

    /**
     * Verifica que el bloque no esté ya ocupado dentro del mismo horario.
     *
     * Corresponde a la restricción UNIQUE(id_horario, id_bloque_horario)
     * en la tabla detalle_horario. Esta validación es la barrera antes
     * de que MySQL lance un error de duplicate key.
     *
     * @param int      $idBloque
     * @param int      $idHorario
     * @param int|null $excluirDetalle  ID de detalle_horario a excluir (reubicación)
     */
    public function validarBloqueEnHorario(
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
            ->where('dh.id_horario', $idHorario)
            ->where('dh.id_bloque_horario', $idBloque)
            ->where('dh.estado', 'activo')
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

        if (! $conflicto) {
            return ValidacionResultado::sinConflictos();
        }

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

    // ── Método de conveniencia para edición manual ───────────────

    /**
     * Validación completa para el endpoint de modificación manual.
     * Incluye el parámetro $excluirDetalle para permitir reubicar
     * una clase dentro del mismo horario sin auto-conflictar.
     */
    /**
     * Validación completa para edición manual.
     * $idPeriodo ya no se recibe suelto — se extrae del modelo $horario.
     */
    public function validarParaEdicionManual(
        int              $idDocente,
        int              $idBloque,
        int              $idHorario,
        int              $idSeccion,
        PeriodoAcademico $periodo,
        Horario          $horario,
        ?int             $excluirDetalle = null,
    ): ValidacionResultado {

        $r1 = $this->validarFechaLimite($periodo);
        if ($r1->tieneConflictos()) { return $r1; }

        $r2 = $this->validarEstadoHorario($horario);
        if ($r2->tieneConflictos()) { return $r2; }

        $r3 = $this->validarDisponibilidadDocente($idDocente, $idBloque);
        $r4 = $this->validarDocenteOcupado($idDocente, $idBloque, $idHorario);
        // $horario contiene id_carrera + id_periodo_academico para anclar al pensum
        $r5 = $this->validarCicloTraslape($idSeccion, $idBloque, $idHorario, $horario);
        $r6 = $this->validarBloqueEnHorario($idBloque, $idHorario, $excluirDetalle);

        return $r3->merge($r4)->merge($r5)->merge($r6);
    }
}
