<?php

namespace App\Services\Horario;

use App\Models\DetalleHorario;
use App\Models\EstadoHorario;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use App\Services\HistorialService;
use App\Services\NotificacionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PersistenciaHorarioService — Sprint 3, Paso 4
 *
 * Responsabilidad: tomar las AsignacionPropuesta validadas por
 * GeneradorParcialService y escribirlas definitivamente en BD.
 *
 * ── Flujo de confirmar() ─────────────────────────────────────────
 *
 *  [Antes de la transacción]
 *   1. Revalidar fecha límite (en memoria — modelo hidratado)
 *   2. Verificar que no hay detalles activos (rechazar si los hay)
 *   3. Obtener id_estado_horario para 'generado'
 *
 *  [Dentro de la transacción]
 *   4. Recargar y bloquear el horario con lockForUpdate()
 *      → Lee el estado real en BD, evita condiciones de carrera
 *   5. Revalidar estado con el horario bloqueado
 *   6. Para cada AsignacionPropuesta:
 *       a. Filtrar contra franjas en memoria (traslape real)
 *          — docente ocupado dentro de esta transacción
 *          — franja del horario ya ocupada en esta transacción
 *          — ciclo_semestre ya ocupado en esta transacción
 *       b. validarTodo() — barrera contra condiciones de carrera externas
 *          (detalles de esta transacción ya visibles en la sesión MySQL)
 *       c. INSERT detalle_horario
 *       d. Actualizar franjas en memoria
 *       e. Historial por detalle
 *   7. UPDATE horario → estado 'generado', fecha_generacion
 *   8. Historial del horario
 *   9. Commit — o rollback total si cualquier paso falla
 *
 * ── Correcciones aplicadas ───────────────────────────────────────
 *
 * C1. lockForUpdate() dentro de la transacción:
 *     El horario se recarga con bloqueo de fila antes de cualquier
 *     escritura. Garantiza ver el estado real aunque otro proceso
 *     lo haya modificado entre generar() y confirmar().
 *
 * C2. Franjas en memoria dentro de la transacción:
 *     validarDocenteOcupado() excluye el horario actual, por lo que
 *     no detecta conflictos entre propuestas del mismo horario dentro
 *     de la misma transacción. Se mantienen tres colecciones de franjas
 *     que se actualizan con cada INSERT y se usan para filtrar
 *     las propuestas siguientes por traslape real de tiempo.
 *
 * C3. Rechazo si hay detalles activos:
 *     confirmar() falla antes de abrir la transacción si el horario
 *     ya tiene detalles activos. Esto previene agregar propuestas
 *     encima de un horario generado previamente.
 *     Para regenerar: llamar primero a limpiarDetalles().
 *
 * C4. lockForUpdate() en limpiarDetalles():
 *     También bloquea el horario antes de modificar detalles y
 *     cambiar estado, por coherencia con confirmar().
 */
class PersistenciaHorarioService
{
    public function __construct(
        private readonly ConflictValidationService $conflictService,
        private readonly NotificacionService       $notificacionService,
    ) {}

    // ── Punto de entrada ────────────────────────────────────────

    /**
     * Persiste las propuestas del GeneracionParcialResultado en BD.
     *
     * @param GeneracionParcialResultado $resultado  Del GeneradorParcialService
     * @param Horario                    $horario    Solo para prevalidación — se recarga con lock dentro
     * @param PeriodoAcademico           $periodo    Modelo hidratado del período
     * @param int                        $idUsuario  Usuario que confirma (NOT NULL en historial)
     */
    public function confirmar(
        GeneracionParcialResultado $resultado,
        Horario                    $horario,
        PeriodoAcademico           $periodo,
        int                        $idUsuario,
    ): PersistenciaResultado {

        // ── Pre-validaciones (antes de la transacción) ──────────
        // Evitan abrir una transacción que fallará con certeza.

        // 1. Fecha límite en memoria — suficiente para prevalidar
        $rFecha = $this->conflictService->validarFechaLimite($periodo);
        if ($rFecha->tieneConflictos()) {
            return PersistenciaResultado::contextoInvalido(
                'No se puede persistir: el período superó la fecha límite de edición.'
            );
        }

        // 2. Sin propuestas
        if ($resultado->asignacionesPropuestas()->isEmpty()) {
            return PersistenciaResultado::contextoInvalido(
                'No hay asignaciones propuestas para persistir.'
            );
        }

        // 3. (C3) Rechazar si el horario ya tiene detalles activos.
        //    Para regenerar: llamar primero a limpiarDetalles().
        $tieneDetalles = DetalleHorario::where('id_horario', $horario->id_horario)
            ->where('estado', 'activo')
            ->exists();

        if ($tieneDetalles) {
            return PersistenciaResultado::contextoInvalido(
                'El horario ya tiene detalles activos. '
                . 'Llame a limpiarDetalles() antes de confirmar una nueva generación.'
            );
        }

        // 4. Obtener id_estado_horario 'generado' (fuera de la transacción — no cambia)
        $idEstadoGenerado = DB::table('estado_horario')
            ->where('nombre_estado', EstadoHorario::GENERADO)
            ->value('id_estado_horario');

        if (! $idEstadoGenerado) {
            return PersistenciaResultado::contextoInvalido(
                "Estado 'generado' no encontrado en la BD. Verifique los seeders."
            );
        }

        // ── Transacción principal ───────────────────────────────
        $detallesInsertados = 0;

        try {
            DB::transaction(function () use (
                $resultado,
                $horario,
                $periodo,
                $idUsuario,
                $idEstadoGenerado,
                &$detallesInsertados,
            ) {
                // (C1) Recargar y bloquear el horario con SELECT FOR UPDATE.
                // Esto garantiza que leemos el estado real en BD y que ningún
                // otro proceso puede modificarlo mientras esta transacción esté abierta.
                $horarioBloqueado = Horario::where('id_horario', $horario->id_horario)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Revalidar estado con la fila real y bloqueada
                $rEstado = $this->conflictService->validarEstadoHorario($horarioBloqueado);
                if ($rEstado->tieneConflictos()) {
                    $estado = $rEstado->primerConflicto()?->contexto['estado_horario'] ?? '';
                    throw new \RuntimeException(
                        "El horario cambió a estado '{$estado}' antes de que se pudiera persistir."
                    );
                }

                // Verificación definitiva de detalles activos dentro de la transacción.
                // La prevalidación antes de la transacción no es suficiente ante
                // concurrencia: dos procesos pueden pasar la prevalidación simultáneamente.
                // Con el horario ya bloqueado (lockForUpdate arriba), este EXISTS
                // lee el estado real e impide que otro proceso inserte detalles
                // entre la prevalidación y este punto.
                $tieneDetallesActivos = DetalleHorario::where('id_horario', $horarioBloqueado->id_horario)
                    ->where('estado', 'activo')
                    ->lockForUpdate()
                    ->exists();

                if ($tieneDetallesActivos) {
                    throw new \RuntimeException(
                        'El horario ya tiene detalles activos. '
                        . 'Llame a limpiarDetalles() antes de confirmar una nueva generación.'
                    );
                }

                // Estado anterior para el historial (del horario real)
                $estadoAnterior = [
                    'id_estado_horario' => $horarioBloqueado->id_estado_horario,
                    'fecha_generacion'  => $horarioBloqueado->fecha_generacion?->toDateTimeString(),
                ];

                // (C2) Franjas en memoria para detectar conflictos entre
                // propuestas de esta misma transacción, usando traslape real.
                // validarDocenteOcupado() excluye el horario actual, por lo que
                // no detecta dos propuestas del mismo docente dentro del mismo horario.
                // Estructura de cada franja: { id_dia, hora_inicio, hora_fin }

                /** Franjas ya insertadas en este horario (cualquier docente) */
                $franjasHorario = collect();

                /** id_docente → Collection de franjas ocupadas */
                $franjasDocente = [];

                /** ciclo_semestre → Collection de franjas ocupadas */
                $franjasCiclo = [];

                foreach ($resultado->asignacionesPropuestas() as $propuesta) {

                    $idDia = $propuesta->idDia;
                    $hi    = $propuesta->horaInicio;
                    $hf    = $propuesta->horaFin;
                    $ciclo = $propuesta->cicloSemestre;

                    // ── Filtros en memoria (traslape real) ──────────────
                    // Detectan conflictos entre propuestas de esta transacción
                    // antes de ejecutar validarTodo() (que no los vería todavía
                    // si aún no se ha hecho el INSERT anterior).

                    // Filtro A: franja ya ocupada en el horario
                    if ($this->traslapaConFranjas($franjasHorario, $idDia, $hi, $hf)) {
                        throw new PersistenciaConflictoException(
                            propuesta: $propuesta,
                            conflicto: ValidacionResultado::conConflictos([
                                ConflictoItem::bloqueOcupadoEnHorario(
                                    idBloque:               $propuesta->idBloque,
                                    horaInicio:             $hi,
                                    horaFin:                $hf,
                                    nombreDia:              $propuesta->nombreDia,
                                    nombreSeccionConflicto: '(otra sección en esta generación)',
                                ),
                            ]),
                        );
                    }

                    // Filtro B: docente ya tiene propuesta en esta franja
                    $fdocente = $franjasDocente[$propuesta->idDocente] ?? collect();
                    if ($this->traslapaConFranjas($fdocente, $idDia, $hi, $hf)) {
                        throw new PersistenciaConflictoException(
                            propuesta: $propuesta,
                            conflicto: ValidacionResultado::conConflictos([
                                ConflictoItem::docenteOcupado(
                                    idDocente:              $propuesta->idDocente,
                                    idBloque:               $propuesta->idBloque,
                                    horaInicio:             $hi,
                                    horaFin:                $hf,
                                    nombreDia:              $propuesta->nombreDia,
                                    idHorarioConflicto:     $horarioBloqueado->id_horario,
                                    nombreCarreraConflicto: '(esta misma generación)',
                                ),
                            ]),
                        );
                    }

                    // Filtro C: ciclo ya tiene propuesta en esta franja
                    if ($ciclo > 0) {
                        $fciclo = $franjasCiclo[$ciclo] ?? collect();
                        if ($this->traslapaConFranjas($fciclo, $idDia, $hi, $hf)) {
                            throw new PersistenciaConflictoException(
                                propuesta: $propuesta,
                                conflicto: ValidacionResultado::conConflictos([
                                    ConflictoItem::cicloTraslape(
                                        cicloSemestre:        $ciclo,
                                        idBloque:             $propuesta->idBloque,
                                        horaInicio:           $hi,
                                        horaFin:              $hf,
                                        nombreDia:            $propuesta->nombreDia,
                                        nombreCursoConflicto: '(otra sección del mismo ciclo en esta generación)',
                                    ),
                                ]),
                            );
                        }
                    }

                    // ── Revalidación contra BD ───────────────────────────
                    // Los INSERTs anteriores de esta transacción ya son
                    // visibles aquí (misma sesión MySQL), por lo que
                    // validarBloqueEnHorario y validarDocenteOcupado
                    // operan sobre datos reales actualizados.
                    $validacion = $this->conflictService->validarTodo(
                        idDocente: $propuesta->idDocente,
                        idBloque:  $propuesta->idBloque,
                        idHorario: $horarioBloqueado->id_horario,
                        idSeccion: $propuesta->idSeccion,
                        periodo:   $periodo,
                        horario:   $horarioBloqueado,
                    );

                    if ($validacion->tieneConflictos()) {
                        throw new PersistenciaConflictoException(
                            propuesta: $propuesta,
                            conflicto: $validacion,
                        );
                    }

                    // ── INSERT detalle_horario ───────────────────────────
                    $detalle = DetalleHorario::create([
                        'id_horario'                  => $horarioBloqueado->id_horario,
                        'id_asignacion_docente_curso' => $propuesta->idAsignacionDocenteCurso,
                        'id_dia'                      => $idDia,
                        'id_bloque_horario'           => $propuesta->idBloque,
                        'estado'                      => 'activo',
                        'fecha_creacion'              => now(),
                        'fecha_actualizacion'         => now(),
                    ]);

                    $detallesInsertados++;

                    // ── Actualizar franjas en memoria ────────────────────
                    $franja = ['id_dia' => $idDia, 'hora_inicio' => $hi, 'hora_fin' => $hf];

                    $franjasHorario->push($franja);

                    if (! isset($franjasDocente[$propuesta->idDocente])) {
                        $franjasDocente[$propuesta->idDocente] = collect();
                    }
                    $franjasDocente[$propuesta->idDocente]->push($franja);

                    if ($ciclo > 0) {
                        if (! isset($franjasCiclo[$ciclo])) {
                            $franjasCiclo[$ciclo] = collect();
                        }
                        $franjasCiclo[$ciclo]->push($franja);
                    }

                    // ── Historial por detalle ────────────────────────────
                    HistorialService::registrar(
                        tabla:      'detalle_horario',
                        idRegistro: $detalle->id_detalle_horario,
                        tipoCambio: 'insert',
                        valorNuevo: [
                            'id_horario'                  => $horarioBloqueado->id_horario,
                            'id_asignacion_docente_curso' => $propuesta->idAsignacionDocenteCurso,
                            'id_bloque_horario'           => $propuesta->idBloque,
                            'id_dia'                      => $idDia,
                            'curso'                       => $propuesta->nombreCurso,
                            'docente'                     => $propuesta->nombreDocente,
                            'hora_inicio'                 => $hi,
                            'hora_fin'                    => $hf,
                            'dia'                         => $propuesta->nombreDia,
                        ],
                        motivo:    'Generación automática de horario',
                        idUsuario: $idUsuario,
                    );
                }

                // ── UPDATE horario → generado ────────────────────────
                $horarioBloqueado->id_estado_horario = $idEstadoGenerado;
                $horarioBloqueado->fecha_generacion  = now();
                $horarioBloqueado->save();

                // ── Historial del horario ────────────────────────────
                HistorialService::registrar(
                    tabla:         'horario',
                    idRegistro:    $horarioBloqueado->id_horario,
                    tipoCambio:    'update',
                    valorAnterior: $estadoAnterior,
                    valorNuevo: [
                        'id_estado_horario'   => $idEstadoGenerado,
                        'fecha_generacion'    => $horarioBloqueado->fecha_generacion->toDateTimeString(),
                        'detalles_insertados' => $detallesInsertados,
                    ],
                    motivo:    "Horario generado automáticamente con {$detallesInsertados} secciones.",
                    idUsuario: $idUsuario,
                );

                // Propagar el estado actualizado al modelo externo
                // para que el caller pueda leerlo sin refresh adicional
                $horario->id_estado_horario = $idEstadoGenerado;
                $horario->fecha_generacion  = $horarioBloqueado->fecha_generacion;
            });

        } catch (PersistenciaConflictoException $e) {
            return PersistenciaResultado::fallido(
                mensaje:   "Conflicto detectado al persistir: {$e->conflicto->primerConflicto()?->mensaje}",
                propuesta: $e->propuesta,
                conflicto: $e->conflicto,
            );

        } catch (\Throwable $e) {
            return PersistenciaResultado::contextoInvalido(
                "Error al persistir el horario: {$e->getMessage()}"
            );
        }

        $nombreEstado = DB::table('estado_horario')
            ->where('id_estado_horario', $idEstadoGenerado)
            ->value('nombre_estado') ?? EstadoHorario::GENERADO;

        $resultado = PersistenciaResultado::exitoso(
            detallesInsertados: $detallesInsertados,
            estadoHorario:      $nombreEstado,
        );

        // Notificar solo si la confirmación fue exitosa, después del commit
        if ($resultado->exitoso) {
            try {
                $nc = DB::table('carrera')->where('id_carrera', $horario->id_carrera)->value('nombre_carrera') ?? '';
                $np = DB::table('periodo_academico')->where('id_periodo_academico', $horario->id_periodo_academico)->value('nombre_periodo') ?? '';
                $this->notificacionService->horarioConfirmado(
                    idCarrera:      $horario->id_carrera,
                    nombreCarrera:  $nc,
                    nombrePeriodo:  $np,
                    totalDetalles:  $detallesInsertados,
                );
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [horarioConfirmado]', ['error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    // ── Limpieza para regeneración ──────────────────────────────

    /**
     * Elimina lógicamente todos los detalles activos de un horario
     * y lo vuelve a estado borrador. Prerequisito para llamar a
     * confirmar() en un horario ya generado.
     *
     * (C4) Recarga y bloquea el horario con lockForUpdate() dentro
     * de la transacción para evitar condiciones de carrera.
     *
     * @param  Horario $horario    Solo para identificar el ID — se recarga con lock
     * @param  int     $idUsuario  Usuario que ejecuta la limpieza
     * @return int     Número de detalles eliminados lógicamente
     * @throws \RuntimeException  Si el horario no es editable
     */
    public function limpiarDetalles(
        Horario $horario,
        int     $idUsuario,
    ): int {

        // Prevalidación rápida con el modelo en memoria
        $rEstado = $this->conflictService->validarEstadoHorario($horario);
        if ($rEstado->tieneConflictos()) {
            $estado = $rEstado->primerConflicto()?->contexto['estado_horario'] ?? '';
            throw new \RuntimeException(
                "No se pueden eliminar detalles: el horario está en estado '{$estado}'."
            );
        }

        $idEstadoBorrador = DB::table('estado_horario')
            ->where('nombre_estado', EstadoHorario::BORRADOR)
            ->value('id_estado_horario');

        if (! $idEstadoBorrador) {
            throw new \RuntimeException(
                "Estado 'borrador' no encontrado en la BD. Verifique los seeders."
            );
        }

        $eliminados = 0;

        DB::transaction(function () use ($horario, $idUsuario, $idEstadoBorrador, &$eliminados) {

            // (C4) Recargar y bloquear el horario dentro de la transacción
            $horarioBloqueado = Horario::where('id_horario', $horario->id_horario)
                ->lockForUpdate()
                ->firstOrFail();

            // Revalidar estado con la fila bloqueada real
            $rEstado = $this->conflictService->validarEstadoHorario($horarioBloqueado);
            if ($rEstado->tieneConflictos()) {
                $estado = $rEstado->primerConflicto()?->contexto['estado_horario'] ?? '';
                throw new \RuntimeException(
                    "El horario cambió a estado '{$estado}' antes de poder limpiarse."
                );
            }

            // Cargar detalles activos con lock de lectura consistente
            $detalles = DetalleHorario::where('id_horario', $horarioBloqueado->id_horario)
                ->where('estado', 'activo')
                ->lockForUpdate()
                ->get();

            foreach ($detalles as $detalle) {
                HistorialService::registrar(
                    tabla:         'detalle_horario',
                    idRegistro:    $detalle->id_detalle_horario,
                    tipoCambio:    'delete',
                    valorAnterior: $detalle->toArray(),
                    motivo:        'Limpieza de detalles para regeneración',
                    idUsuario:     $idUsuario,
                );
                $detalle->update(['estado' => 'inactivo']);
                $eliminados++;
            }

            // Volver horario a borrador
            HistorialService::registrar(
                tabla:         'horario',
                idRegistro:    $horarioBloqueado->id_horario,
                tipoCambio:    'update',
                valorAnterior: ['id_estado_horario' => $horarioBloqueado->id_estado_horario],
                valorNuevo:    ['id_estado_horario' => $idEstadoBorrador],
                motivo:        'Horario vuelto a borrador para regeneración',
                idUsuario:     $idUsuario,
            );

            $horarioBloqueado->update(['id_estado_horario' => $idEstadoBorrador]);

            // Propagar al modelo externo
            $horario->id_estado_horario = $idEstadoBorrador;
        });

        // Notificar solo si la limpieza fue exitosa (eliminados > 0), después del commit
        if ($eliminados > 0) {
            try {
                $nc = DB::table('carrera')->where('id_carrera', $horario->id_carrera)->value('nombre_carrera') ?? '';
                $np = DB::table('periodo_academico')->where('id_periodo_academico', $horario->id_periodo_academico)->value('nombre_periodo') ?? '';
                $this->notificacionService->horarioRegenerado(
                    idCarrera:     $horario->id_carrera,
                    nombreCarrera: $nc,
                    nombrePeriodo: $np,
                );
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [horarioRegenerado]', ['error' => $e->getMessage()]);
            }
        }

        return $eliminados;
    }

    // ── Función de traslape ─────────────────────────────────────

    /**
     * Verifica si la franja candidata traslapa con alguna franja
     * de la colección.
     *
     * Condición de traslape:
     *   franja.id_dia     = idDia
     *   franja.hora_inicio < horaFin     (existente empieza antes de que termine el candidato)
     *   franja.hora_fin    > horaInicio  (existente termina después de que empieza el candidato)
     *
     * Comparación de strings "HH:MM:SS" — válida para tiempos del mismo día.
     */
    private function traslapaConFranjas(
        Collection $franjas,
        int        $idDia,
        string     $horaInicio,
        string     $horaFin,
    ): bool {
        foreach ($franjas as $f) {
            if (
                (int) $f['id_dia'] === $idDia
                && $f['hora_inicio'] < $horaFin
                && $f['hora_fin']    > $horaInicio
            ) {
                return true;
            }
        }
        return false;
    }
}
