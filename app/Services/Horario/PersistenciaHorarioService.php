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
 *     Se mantienen dos colecciones que detectan conflictos entre propuestas
 *     de la misma transacción antes de cada INSERT:
 *       $franjasDocente — mismo docente no puede tener 2 clases simultáneas
 *       $franjasCiclo   — mismo ciclo no puede tener 2 cursos simultáneos
 *
 *     ELIMINADO: $franjasHorario (Filtro A global de franja del horario).
 *     Causaba que ciclos distintos no pudieran usar la misma franja dentro
 *     del mismo horario maestro. Con aulas fuera del alcance del proyecto,
 *     el paralelismo entre ciclos es válido y deseable.
 *
 *     validarDocenteOcupado() ya no excluye el horario actual completo;
 *     solo excluye un detalle específico cuando se pasa $excluirDetalleId.
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

        // 3. (C3) Rechazar si el horario ya tiene detalles activos PARA ESTA JORNADA.
        //    La limpieza selectiva (limpiarDetalles con idCarreraJornada) debe haberse
        //    llamado antes. Detalles de otras jornadas son válidos y se preservan.
        $idCarreraJornada = $resultado->idCarreraJornada();
        $tieneDetallesJornada = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc', 'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->where('dh.id_horario', $horario->id_horario)
            ->where('dh.estado', 'activo')
            ->where('s.id_carrera_jornada', $idCarreraJornada)
            ->exists();

        if ($tieneDetallesJornada) {
            return PersistenciaResultado::contextoInvalido(
                'El horario ya tiene detalles activos para esta jornada. '
                . 'Llame a limpiarDetalles() con el id_carrera_jornada correspondiente antes de confirmar.'
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
                $idCarreraJornada,
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

                // Verificación definitiva de detalles PARA ESTA JORNADA dentro de la transacción.
                // REINGENIERÍA: ya no es global por id_horario. Solo verifica la jornada
                // que se está confirmando. Detalles de otras jornadas son válidos y se ignoran.
                //
                // Con el horario ya bloqueado (lockForUpdate arriba), esta query
                // lee el estado real e impide que otro proceso inserte detalles
                // de la misma jornada entre la prevalidación y este punto.
                $tieneDetallesJornada = DB::table('detalle_horario as dh')
                    ->join('asignacion_docente_curso as adc',
                        'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
                    ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
                    ->where('dh.id_horario', $horarioBloqueado->id_horario)
                    ->where('dh.estado', 'activo')
                    ->where('s.id_carrera_jornada', $idCarreraJornada)
                    ->lockForUpdate()
                    ->exists();

                if ($tieneDetallesJornada) {
                    throw new \RuntimeException(
                        "El horario ya tiene detalles activos para la jornada {$idCarreraJornada}. "
                        . 'Llame a limpiarDetalles() con este id_carrera_jornada antes de confirmar.'
                    );
                }

                // Estado anterior para el historial (del horario real)
                $estadoAnterior = [
                    'id_estado_horario' => $horarioBloqueado->id_estado_horario,
                    'fecha_generacion'  => $horarioBloqueado->fecha_generacion?->toDateTimeString(),
                ];

                // (C2) Franjas en memoria para detectar conflictos entre
                // propuestas de esta misma transacción, usando traslape real.
                //
                // ARQUITECTURA PARALELISMO (post-hotfix paralelo_ciclos_v2):
                //   Filtro A ($franjasHorario) ELIMINADO.
                //   Causaba que ciclos distintos no pudieran usar la misma franja
                //   dentro del mismo horario maestro. Con aulas fuera del alcance,
                //   el paralelismo entre ciclos es válido y deseable.
                //
                //   MANTENIDOS:
                //   Filtro B ($franjasDocente) — mismo docente no puede tener 2 clases simultáneas
                //   Filtro C ($franjasCiclo)   — mismo ciclo no puede tener 2 cursos simultáneos
                // Estructura de cada franja: { id_dia, hora_inicio, hora_fin }

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
                    //
                    // Filtro A ($franjasHorario) ELIMINADO — ver nota en declaración.

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
                        idAsignacionDocenteCurso: $propuesta->idAsignacionDocenteCurso,
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
    /**
     * Elimina lógicamente los detalles activos de UN HORARIO PARA UNA JORNADA ESPECÍFICA
     * y vuelve el horario a estado borrador solo si ya no quedan detalles activos.
     *
     * REINGENIERÍA: antes limpiaba todos los detalles del id_horario. Ahora
     * solo limpia los detalles cuyas secciones pertenecen a id_carrera_jornada.
     * Esto permite regenerar Matutina sin borrar los detalles de Vespertina.
     *
     * Criterio de selección de detalles a limpiar:
     *   detalle_horario
     *     → id_asignacion_docente_curso
     *       → asignacion_docente_curso.id_seccion
     *         → seccion.id_carrera_jornada = $idCarreraJornada
     *
     * El horario vuelve a borrador ÚNICAMENTE si ya no quedan detalles activos
     * de ninguna jornada. Si otras jornadas tienen detalles, el estado se conserva.
     *
     * @param  Horario $horario           Solo para identificar el ID — se recarga con lock
     * @param  int     $idCarreraJornada  Jornada a limpiar
     * @param  int     $idUsuario         Usuario que ejecuta la limpieza
     * @return int     Número de detalles eliminados lógicamente
     * @throws \RuntimeException  Si el horario no es editable
     */
    public function limpiarDetalles(
        Horario $horario,
        int     $idCarreraJornada,
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

        DB::transaction(function () use ($horario, $idCarreraJornada, $idUsuario, $idEstadoBorrador, &$eliminados) {

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

            // Obtener IDs de detalles activos de esta jornada específica
            // Cadena: detalle_horario → asignacion_docente_curso → seccion.id_carrera_jornada
            $idsDetallesJornada = DB::table('detalle_horario as dh')
                ->join('asignacion_docente_curso as adc', 'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
                ->join('seccion as s',                    'adc.id_seccion',                  '=', 's.id_seccion')
                ->where('dh.id_horario', $horarioBloqueado->id_horario)
                ->where('dh.estado',     'activo')
                ->where('s.id_carrera_jornada', $idCarreraJornada)
                ->pluck('dh.id_detalle_horario');

            // Cargar y limpiar solo esos detalles
            $detalles = DetalleHorario::whereIn('id_detalle_horario', $idsDetallesJornada)
                ->lockForUpdate()
                ->get();

            foreach ($detalles as $detalle) {
                HistorialService::registrar(
                    tabla:         'detalle_horario',
                    idRegistro:    $detalle->id_detalle_horario,
                    tipoCambio:    'delete',
                    valorAnterior: $detalle->toArray(),
                    motivo:        "Limpieza selectiva por jornada {$idCarreraJornada} para regeneración",
                    idUsuario:     $idUsuario,
                );
                $detalle->update(['estado' => 'inactivo']);
                $eliminados++;
            }

            // Verificar si quedan detalles activos de OTRAS jornadas
            $quedanDetalles = DetalleHorario::where('id_horario', $horarioBloqueado->id_horario)
                ->where('estado', 'activo')
                ->exists();

            // Solo volver a borrador si no queda ningún detalle activo
            if (! $quedanDetalles) {
                HistorialService::registrar(
                    tabla:         'horario',
                    idRegistro:    $horarioBloqueado->id_horario,
                    tipoCambio:    'update',
                    valorAnterior: ['id_estado_horario' => $horarioBloqueado->id_estado_horario],
                    valorNuevo:    ['id_estado_horario' => $idEstadoBorrador],
                    motivo:        'Horario vuelto a borrador para regeneración (sin detalles activos)',
                    idUsuario:     $idUsuario,
                );
                $horarioBloqueado->update(['id_estado_horario' => $idEstadoBorrador]);
                $horario->id_estado_horario = $idEstadoBorrador;
            }

            // Historial de la limpieza selectiva por jornada
            HistorialService::registrar(
                tabla:      'horario',
                idRegistro: $horarioBloqueado->id_horario,
                tipoCambio: 'update',
                valorNuevo: [
                    'accion'             => 'limpieza_selectiva_jornada',
                    'id_carrera_jornada' => $idCarreraJornada,
                    'detalles_eliminados'=> $eliminados,
                    'quedan_detalles'    => $quedanDetalles,
                ],
                motivo:    "Limpieza de {$eliminados} detalles de jornada {$idCarreraJornada} para regeneración",
                idUsuario: $idUsuario,
            );
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
