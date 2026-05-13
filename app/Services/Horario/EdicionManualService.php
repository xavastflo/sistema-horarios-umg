<?php

namespace App\Services\Horario;

use App\Models\DetalleHorario;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use App\Services\HistorialService;
use App\Services\NotificacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EdicionManualService — Sprint 3, Paso 5
 *
 * Responsabilidad: permitir la modificación manual de un detalle de
 * horario (mover una clase a otro bloque, o eliminarla del horario)
 * con todas las validaciones y registro en historial_cambios.
 *
 * ── Flujo de moverDetalle() ─────────────────────────────────────
 *
 *  [Antes de la transacción]
 *   1. Prevalidar fecha límite (en memoria, falla rápido)
 *
 *  [Dentro de la transacción]
 *   2. lockForUpdate() sobre el horario
 *   3. Revalidar estado del horario con fila bloqueada
 *   4. lockForUpdate() sobre el período y revalidar fecha límite (definitivo)
 *   5. lockForUpdate() sobre el detalle a mover
 *   6. Cargar la asignación docente-sección del detalle
 *   7. Cargar el bloque destino con JOIN a carrera_jornada —
 *      validar que id_carrera = horarioBloqueado.id_carrera (C1)
 *   8. validarParaEdicionManual() con $excluirDetalle y $periodoBloqueado (C2)
 *   9. UPDATE detalle_horario
 *  10. Historial
 *
 * ── Correcciones aplicadas ───────────────────────────────────────
 *
 * C1. Bloque destino debe pertenecer a la misma carrera del horario.
 *     Se hace JOIN bloque_horario → carrera_jornada y se verifica
 *     carrera_jornada.id_carrera = horarioBloqueado.id_carrera.
 *     Si no coincide: rechazar con tipo 'bloque_fuera_de_carrera'.
 *
 * C2. Fecha límite revalidada dentro de la transacción.
 *     Tras bloquear el horario se recarga el período con lockForUpdate()
 *     y se revalida validarFechaLimite($periodoBloqueado).
 *     $periodoBloqueado reemplaza a $periodo en validarParaEdicionManual().
 *     Aplica tanto en moverDetalle() como en eliminarDetalle().
 *
 * ── Por qué $excluirDetalle ──────────────────────────────────────
 *
 * validarBloqueEnHorario() busca si el bloque candidato ya está
 * ocupado en detalle_horario. Al mover, el registro original sigue
 * activo hasta el UPDATE. Sin $excluirDetalle el sistema rechazaría
 * mover al mismo bloque (auto-conflicto). Con él, ignora el propio
 * registro al buscar ocupación.
 */
class EdicionManualService
{
    public function __construct(
        private readonly ConflictValidationService $conflictService,
        private readonly NotificacionService       $notificacionService,
    ) {}

    // ── moverDetalle ────────────────────────────────────────────

    /**
     * Mueve una clase a un bloque horario diferente dentro del mismo horario.
     *
     * @param int              $idDetalle     El detalle_horario a mover
     * @param int              $idBloqueNuevo El bloque destino
     * @param Horario          $horario       Modelo hidratado — se recarga con lock
     * @param PeriodoAcademico $periodo       Solo para prevalidación — se recarga con lock dentro
     * @param int              $idUsuario     Usuario que realiza el cambio
     */
    public function moverDetalle(
        int              $idDetalle,
        int              $idBloqueNuevo,
        Horario          $horario,
        PeriodoAcademico $periodo,
        int              $idUsuario,
    ): EdicionManualResultado {

        // Prevalidación (antes de la transacción — falla rápido en memoria)
        $rFecha = $this->conflictService->validarFechaLimite($periodo);
        if ($rFecha->tieneConflictos()) {
            return EdicionManualResultado::rechazado(
                'fecha_limite_vencida',
                'No se puede editar: el período superó la fecha límite.',
                $rFecha,
            );
        }

        $detalleActualizado = null;

        try {
            DB::transaction(function () use (
                $idDetalle,
                $idBloqueNuevo,
                $horario,
                $idUsuario,
                &$detalleActualizado,
            ) {
                // ── 2. Bloquear el horario ──────────────────────────
                $horarioBloqueado = Horario::where('id_horario', $horario->id_horario)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ── 3. Revalidar estado con fila real ───────────────
                $rEstado = $this->conflictService->validarEstadoHorario($horarioBloqueado);
                if ($rEstado->tieneConflictos()) {
                    $estado = $rEstado->primerConflicto()?->contexto['estado_horario'] ?? '';
                    throw new EdicionManualException(
                        tipo:    'horario_no_editable',
                        mensaje: "El horario está en estado '{$estado}' y no puede modificarse.",
                    );
                }

                // ── 4. (C2) Recargar y bloquear el período ──────────
                // La prevalidación usa el modelo en memoria. Esta es la
                // verificación definitiva: otro proceso pudo haber vencido
                // la fecha límite entre la prevalidación y este punto.
                $periodoBloqueado = PeriodoAcademico::where(
                    'id_periodo_academico',
                    $horarioBloqueado->id_periodo_academico
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                $rFechaDefinitiva = $this->conflictService->validarFechaLimite($periodoBloqueado);
                if ($rFechaDefinitiva->tieneConflictos()) {
                    throw new EdicionManualException(
                        tipo:       'fecha_limite_vencida',
                        mensaje:    'El período superó la fecha límite de edición de horarios.',
                        validacion: $rFechaDefinitiva,
                    );
                }

                // ── 5. Bloquear el detalle a mover ──────────────────
                $detalle = DetalleHorario::where('id_detalle_horario', $idDetalle)
                    ->where('id_horario', $horarioBloqueado->id_horario)
                    ->where('estado', 'activo')
                    ->lockForUpdate()
                    ->first();

                if (! $detalle) {
                    throw new EdicionManualException(
                        tipo:    'detalle_no_encontrado',
                        mensaje: "El detalle #{$idDetalle} no existe, no pertenece a este horario o no está activo.",
                    );
                }

                // ── 6. Cargar la asignación docente-sección ─────────
                $asignacion = DB::table('asignacion_docente_curso as adc')
                    ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
                    ->where('adc.id_asignacion_docente_curso', $detalle->id_asignacion_docente_curso)
                    ->select(['adc.id_docente', 'adc.id_seccion'])
                    ->first();

                if (! $asignacion) {
                    throw new EdicionManualException(
                        tipo:    'asignacion_no_encontrada',
                        mensaje: 'No se encontró la asignación docente-sección de este detalle.',
                    );
                }

                // ── 7. (C1) Cargar el bloque destino con validación de carrera ─
                // JOIN a carrera_jornada para verificar que el bloque
                // pertenece a la misma carrera del horario.
                $bloqueNuevo = DB::table('bloque_horario as bh')
                    ->join('carrera_jornada as cj', 'bh.id_carrera_jornada', '=', 'cj.id_carrera_jornada')
                    ->where('bh.id_bloque_horario', $idBloqueNuevo)
                    ->where('bh.estado', 'activo')
                    ->select([
                        'bh.id_bloque_horario',
                        'bh.id_dia',
                        'bh.hora_inicio',
                        'bh.hora_fin',
                        'cj.id_carrera',
                    ])
                    ->first();

                if (! $bloqueNuevo) {
                    throw new EdicionManualException(
                        tipo:    'bloque_no_encontrado',
                        mensaje: "El bloque horario #{$idBloqueNuevo} no existe o no está activo.",
                    );
                }

                // Verificar que el bloque pertenece a la misma carrera del horario
                if ((int) $bloqueNuevo->id_carrera !== (int) $horarioBloqueado->id_carrera) {
                    throw new EdicionManualException(
                        tipo:    'bloque_fuera_de_carrera',
                        mensaje: "El bloque #{$idBloqueNuevo} pertenece a una carrera diferente a la del horario. "
                               . "Solo se pueden usar bloques de la misma carrera.",
                    );
                }

                // ── 8. Validación completa con $excluirDetalle ───────
                // $periodoBloqueado (fila real) reemplaza a $periodo
                // para garantizar coherencia dentro de la transacción.
                $validacion = $this->conflictService->validarParaEdicionManual(
                    idDocente:      $asignacion->id_docente,
                    idBloque:       $idBloqueNuevo,
                    idHorario:      $horarioBloqueado->id_horario,
                    idSeccion:      $asignacion->id_seccion,
                    periodo:        $periodoBloqueado,
                    horario:        $horarioBloqueado,
                    excluirDetalle: $idDetalle,
                );

                if ($validacion->tieneConflictos()) {
                    throw new EdicionManualException(
                        tipo:       'conflicto_validacion',
                        mensaje:    'No se puede mover la clase: existen conflictos de horario.',
                        validacion: $validacion,
                    );
                }

                // ── 9. UPDATE detalle_horario ────────────────────────
                $valorAnterior = [
                    'id_bloque_horario' => $detalle->id_bloque_horario,
                    'id_dia'            => $detalle->id_dia,
                ];

                $detalle->update([
                    'id_bloque_horario'  => $idBloqueNuevo,
                    'id_dia'             => $bloqueNuevo->id_dia,
                    'fecha_actualizacion'=> now(),
                ]);

                $detalleActualizado = $detalle->fresh();

                // ── 10. Historial ────────────────────────────────────
                HistorialService::registrar(
                    tabla:         'detalle_horario',
                    idRegistro:    $idDetalle,
                    tipoCambio:    'update',
                    valorAnterior: $valorAnterior,
                    valorNuevo: [
                        'id_bloque_horario' => $idBloqueNuevo,
                        'id_dia'            => $bloqueNuevo->id_dia,
                        'hora_inicio'       => $bloqueNuevo->hora_inicio,
                        'hora_fin'          => $bloqueNuevo->hora_fin,
                    ],
                    motivo:    'Modificación manual de bloque horario',
                    idUsuario: $idUsuario,
                );
            });

        } catch (EdicionManualException $e) {
            return EdicionManualResultado::rechazado(
                tipo:       $e->tipo,
                mensaje:    $e->mensaje,
                validacion: $e->validacion,
            );
        } catch (\Throwable $e) {
            return EdicionManualResultado::rechazado(
                tipo:    'error_inesperado',
                mensaje: "Error al mover el detalle: {$e->getMessage()}",
            );
        }

        $resultado = EdicionManualResultado::exitoso(
            detalle: $detalleActualizado,
            mensaje: 'Clase movida correctamente al nuevo bloque.',
        );

        // Notificar solo si el evento fue exitoso, después del commit
        // $valorAnterior fue capturado ANTES del UPDATE, por lo que contiene
        // el id_bloque_horario original — se usa para construir la descripción real.
        if ($resultado->exitoso && $detalleActualizado) {
            try {
                // Resolver id_usuario del docente desde la asignación
                $idUsuarioDocente = \Illuminate\Support\Facades\DB::table('asignacion_docente_curso as adc')
                    ->join('docente as d', 'adc.id_docente', '=', 'd.id_docente')
                    ->where('adc.id_asignacion_docente_curso', $detalleActualizado->id_asignacion_docente_curso)
                    ->value('d.id_usuario');

                // Descripción real del bloque anterior (de donde vino la clase)
                // Usa $valorAnterior['id_bloque_horario'] capturado antes del UPDATE
                $descBloqueAnterior = \Illuminate\Support\Facades\DB::table('bloque_horario as bh')
                    ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
                    ->where('bh.id_bloque_horario', $valorAnterior['id_bloque_horario'])
                    ->selectRaw("CONCAT(dia.nombre_dia, ' ', bh.hora_inicio, '-', bh.hora_fin) as descripcion")
                    ->value('descripcion') ?? 'bloque anterior';

                // Descripción del bloque nuevo (a donde fue movida la clase)
                $descBloqueNuevo = \Illuminate\Support\Facades\DB::table('bloque_horario as bh')
                    ->join('dia', 'bh.id_dia', '=', 'dia.id_dia')
                    ->where('bh.id_bloque_horario', $detalleActualizado->id_bloque_horario)
                    ->selectRaw("CONCAT(dia.nombre_dia, ' ', bh.hora_inicio, '-', bh.hora_fin) as descripcion")
                    ->value('descripcion') ?? 'nuevo bloque';

                $nombreCurso = \Illuminate\Support\Facades\DB::table('asignacion_docente_curso as adc')
                    ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
                    ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
                    ->where('adc.id_asignacion_docente_curso', $detalleActualizado->id_asignacion_docente_curso)
                    ->value('c.nombre_curso') ?? 'curso';

                $this->notificacionService->detalleMovido(
                    idUsuarioDocente: $idUsuarioDocente,
                    idCarrera:        $horario->id_carrera,
                    nombreCurso:      $nombreCurso,
                    bloqueAnterior:   $descBloqueAnterior,
                    bloqueNuevo:      $descBloqueNuevo,
                );
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [detalleMovido]', ['error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    // ── eliminarDetalle ─────────────────────────────────────────

    /**
     * Elimina lógicamente un detalle de horario.
     * La sección queda sin bloque asignado (no se elimina la sección ni la asignación).
     *
     * @param int              $idDetalle  El detalle_horario a eliminar
     * @param Horario          $horario    Solo para identificación — se recarga con lock
     * @param PeriodoAcademico $periodo    Solo para prevalidación — se recarga con lock dentro
     * @param int              $idUsuario  Usuario que realiza el cambio
     * @param string|null      $motivo     Motivo opcional para el historial
     */
    public function eliminarDetalle(
        int              $idDetalle,
        Horario          $horario,
        PeriodoAcademico $periodo,
        int              $idUsuario,
        ?string          $motivo = null,
    ): EdicionManualResultado {

        // Prevalidación en memoria
        $rFecha = $this->conflictService->validarFechaLimite($periodo);
        if ($rFecha->tieneConflictos()) {
            return EdicionManualResultado::rechazado(
                'fecha_limite_vencida',
                'No se puede editar: el período superó la fecha límite.',
                $rFecha,
            );
        }

        try {
            DB::transaction(function () use (
                $idDetalle,
                $horario,
                $idUsuario,
                $motivo,
            ) {
                // Bloquear el horario
                $horarioBloqueado = Horario::where('id_horario', $horario->id_horario)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Revalidar estado
                $rEstado = $this->conflictService->validarEstadoHorario($horarioBloqueado);
                if ($rEstado->tieneConflictos()) {
                    $estado = $rEstado->primerConflicto()?->contexto['estado_horario'] ?? '';
                    throw new EdicionManualException(
                        tipo:    'horario_no_editable',
                        mensaje: "El horario está en estado '{$estado}' y no puede modificarse.",
                    );
                }

                // (C2) Recargar y bloquear el período — verificación definitiva
                $periodoBloqueado = PeriodoAcademico::where(
                    'id_periodo_academico',
                    $horarioBloqueado->id_periodo_academico
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                $rFechaDefinitiva = $this->conflictService->validarFechaLimite($periodoBloqueado);
                if ($rFechaDefinitiva->tieneConflictos()) {
                    throw new EdicionManualException(
                        tipo:       'fecha_limite_vencida',
                        mensaje:    'El período superó la fecha límite de edición de horarios.',
                        validacion: $rFechaDefinitiva,
                    );
                }

                // Bloquear el detalle
                $detalle = DetalleHorario::where('id_detalle_horario', $idDetalle)
                    ->where('id_horario', $horarioBloqueado->id_horario)
                    ->where('estado', 'activo')
                    ->lockForUpdate()
                    ->first();

                if (! $detalle) {
                    throw new EdicionManualException(
                        tipo:    'detalle_no_encontrado',
                        mensaje: "El detalle #{$idDetalle} no existe, no pertenece a este horario o no está activo.",
                    );
                }

                // Historial antes del cambio
                HistorialService::registrar(
                    tabla:         'detalle_horario',
                    idRegistro:    $idDetalle,
                    tipoCambio:    'delete',
                    valorAnterior: [
                        'id_bloque_horario'           => $detalle->id_bloque_horario,
                        'id_dia'                      => $detalle->id_dia,
                        'id_asignacion_docente_curso' => $detalle->id_asignacion_docente_curso,
                    ],
                    motivo:    $motivo ?? 'Eliminación manual de clase del horario',
                    idUsuario: $idUsuario,
                );

                // Eliminación lógica
                $detalle->update([
                    'estado'             => 'inactivo',
                    'fecha_actualizacion'=> now(),
                ]);
            });

        } catch (EdicionManualException $e) {
            return EdicionManualResultado::rechazado(
                tipo:    $e->tipo,
                mensaje: $e->mensaje,
            );
        } catch (\Throwable $e) {
            return EdicionManualResultado::rechazado(
                tipo:    'error_inesperado',
                mensaje: "Error al eliminar el detalle: {$e->getMessage()}",
            );
        }

        $resultado = EdicionManualResultado::exitoso(
            detalle: null,
            mensaje: 'Clase eliminada del horario correctamente. La sección queda sin bloque asignado.',
        );

        // Notificar solo si el evento fue exitoso, después del commit
        if ($resultado->exitoso) {
            try {
                // idDetalle pasado como parámetro es el que se eliminó
                $info = \Illuminate\Support\Facades\DB::table('asignacion_docente_curso as adc')
                    ->join('docente as d', 'adc.id_docente', '=', 'd.id_docente')
                    ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
                    ->join('curso as c', 's.id_curso', '=', 'c.id_curso')
                    ->join('detalle_horario as dh', 'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
                    ->where('dh.id_detalle_horario', $idDetalle)
                    ->select(['d.id_usuario as id_usuario_docente', 'c.nombre_curso'])
                    ->first();
                if ($info) {
                    $this->notificacionService->detalleEliminado(
                        idUsuarioDocente: $info->id_usuario_docente,
                        idCarrera:        $horario->id_carrera,
                        nombreCurso:      $info->nombre_curso,
                        bloqueDescripcion: 'bloque eliminado',
                    );
                }
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [detalleEliminado]', ['error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }
}
