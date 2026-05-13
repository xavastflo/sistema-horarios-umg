<?php

namespace App\Services\Horario;

use App\Models\EstadoHorario;
use App\Models\Horario;
use App\Services\HistorialService;
use App\Services\NotificacionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * HorarioStateService — Sprint 3, Paso 6
 *
 * Responsabilidad: ejecutar las transiciones administrativas de estado
 * del horario con validación de la máquina de estados, bloqueo de fila,
 * actualización de campos de auditoría y registro en historial_cambios.
 *
 * ── Máquina de estados ───────────────────────────────────────────
 *
 *   borrador ──[generador automático]──▶ generado
 *                                            │
 *                                         aprobar
 *                                            │
 *                                            ▼
 *                                        aprobado ──bloquear──▶ bloqueado
 *                                            │                      │
 *                                         publicar              publicar
 *                                            │                      │
 *                                            └──────────┬───────────┘
 *                                                       ▼
 *                                                   publicado  ← terminal
 *
 * Transiciones válidas:
 *   generado  → aprobado   (aprobar)
 *   aprobado  → bloqueado  (bloquear)
 *   aprobado  → publicado  (publicar)
 *   bloqueado → publicado  (publicar)
 *
 * Estados no editables post-publicación:
 *   publicado → ninguno (estado terminal)
 *
 * ── Campos de auditoría actualizados ────────────────────────────
 *
 *   aprobar:  fecha_aprobacion = now()
 *   bloquear: fecha_bloqueo    = now()
 *   publicar: (sin campo adicional — no existe fecha_publicacion en SQL oficial)
 *
 * ── Separación de responsabilidades ─────────────────────────────
 *
 * Este servicio gestiona transiciones administrativas.
 * La edición de contenido (clases, bloques) es responsabilidad de
 * EdicionManualService y PersistenciaHorarioService.
 * validarEstadoHorario() de ConflictValidationService controla si
 * el horario es editable para contenido — es independiente de este flujo.
 */
class HorarioStateService
{
    /**
     * Mapa de transiciones válidas.
     * Estructura: estado_origen => [ accion => estado_destino ]
     */
    private const TRANSICIONES = [
        EstadoHorario::GENERADO  => ['aprobar'   => EstadoHorario::APROBADO],
        EstadoHorario::APROBADO  => [
            'bloquear' => EstadoHorario::BLOQUEADO,
            'publicar' => EstadoHorario::PUBLICADO,
        ],
        EstadoHorario::BLOQUEADO => ['publicar' => EstadoHorario::PUBLICADO],
    ];

    public function __construct(
        private readonly NotificacionService $notificacionService,
    ) {}

    // ── Métodos públicos de transición ──────────────────────────

    /**
     * Aprueba un horario.
     * Transición válida: generado → aprobado
     * Actualiza: fecha_aprobacion = now()
     *
     * @param Horario $horario   Solo para identificación — se recarga con lock
     * @param int     $idUsuario Usuario administrador que aprueba
     * @param string|null $observaciones Observaciones opcionales
     */
    public function aprobar(
        Horario $horario,
        int     $idUsuario,
        ?string $observaciones = null,
    ): HorarioStateResultado {
        $resultado = $this->ejecutarTransicion(
            horario:       $horario,
            accion:        'aprobar',
            idUsuario:     $idUsuario,
            campos:        ['fecha_aprobacion' => now()],
            observaciones: $observaciones,
            motivo:        'Aprobación de horario por administrador',
        );

        if ($resultado->exitoso && $resultado->horario) {
            try {
                $h = $resultado->horario;
                $nc = \Illuminate\Support\Facades\DB::table('carrera')->where('id_carrera', $h->id_carrera)->value('nombre_carrera') ?? '';
                $np = \Illuminate\Support\Facades\DB::table('periodo_academico')->where('id_periodo_academico', $h->id_periodo_academico)->value('nombre_periodo') ?? '';
                $this->notificacionService->horarioAprobado($h->id_horario, $h->id_carrera, $nc, $np);
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [aprobar]', ['error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    /**
     * Bloquea un horario aprobado.
     * Transición válida: aprobado → bloqueado
     * Actualiza: fecha_bloqueo = now()
     * Un horario bloqueado sigue siendo un compromiso real para los docentes.
     *
     * @param Horario $horario   Solo para identificación — se recarga con lock
     * @param int     $idUsuario Usuario administrador que bloquea
     * @param string|null $observaciones Motivo del bloqueo
     */
    public function bloquear(
        Horario $horario,
        int     $idUsuario,
        ?string $observaciones = null,
    ): HorarioStateResultado {
        $resultado = $this->ejecutarTransicion(
            horario:       $horario,
            accion:        'bloquear',
            idUsuario:     $idUsuario,
            campos:        ['fecha_bloqueo' => now()],
            observaciones: $observaciones,
            motivo:        'Bloqueo de horario por administrador',
        );

        if ($resultado->exitoso && $resultado->horario) {
            try {
                $h = $resultado->horario;
                $nc = \Illuminate\Support\Facades\DB::table('carrera')->where('id_carrera', $h->id_carrera)->value('nombre_carrera') ?? '';
                $np = \Illuminate\Support\Facades\DB::table('periodo_academico')->where('id_periodo_academico', $h->id_periodo_academico)->value('nombre_periodo') ?? '';
                $this->notificacionService->horarioBloqueado($h->id_horario, $h->id_carrera, $nc, $np);
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [bloquear]', ['error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    /**
     * Publica un horario.
     * Transiciones válidas: aprobado → publicado, bloqueado → publicado
     * Sin campo de auditoría adicional (no existe fecha_publicacion en SQL oficial).
     * Estado terminal — no tiene salida.
     *
     * @param Horario $horario   Solo para identificación — se recarga con lock
     * @param int     $idUsuario Usuario administrador que publica
     * @param string|null $observaciones Observaciones de publicación
     */
    public function publicar(
        Horario $horario,
        int     $idUsuario,
        ?string $observaciones = null,
    ): HorarioStateResultado {
        $resultado = $this->ejecutarTransicion(
            horario:       $horario,
            accion:        'publicar',
            idUsuario:     $idUsuario,
            campos:        [],                    // sin campo de fecha adicional
            observaciones: $observaciones,
            motivo:        'Publicación de horario por administrador',
        );

        if ($resultado->exitoso && $resultado->horario) {
            try {
                $h = $resultado->horario;
                $nc = \Illuminate\Support\Facades\DB::table('carrera')->where('id_carrera', $h->id_carrera)->value('nombre_carrera') ?? '';
                $np = \Illuminate\Support\Facades\DB::table('periodo_academico')->where('id_periodo_academico', $h->id_periodo_academico)->value('nombre_periodo') ?? '';
                $this->notificacionService->horarioPublicado($h->id_horario, $h->id_carrera, $nc, $np);
            } catch (\Throwable $e) {
                Log::error('Notificacion fallida [publicar]', ['error' => $e->getMessage()]);
            }
        }

        return $resultado;
    }

    // ── Motor de transición ─────────────────────────────────────

    /**
     * Ejecuta una transición de estado de forma transaccional.
     *
     * Flujo dentro de la transacción:
     *   1. lockForUpdate() sobre el horario
     *   2. Cargar el nombre de estado actual (via estado_horario)
     *   3. Validar que la transición es válida en la máquina de estados
     *   4. Obtener id_estado_horario del estado destino
     *   5. UPDATE horario: id_estado_horario + campos de auditoría + observaciones
     *   6. Historial con estado anterior y nuevo
     *
     * @param Horario     $horario       Modelo para extraer el ID
     * @param string      $accion        'aprobar' | 'bloquear' | 'publicar'
     * @param int         $idUsuario     Usuario que ejecuta la transición
     * @param array       $campos        Campos adicionales a actualizar (ej. fecha_aprobacion)
     * @param string|null $observaciones Observaciones opcionales
     * @param string      $motivo        Texto del historial
     */
    private function ejecutarTransicion(
        Horario $horario,
        string  $accion,
        int     $idUsuario,
        array   $campos,
        ?string $observaciones,
        string  $motivo,
    ): HorarioStateResultado {

        $estadoAnteriorNombre = null;
        $estadoNuevoNombre    = null;
        $horarioActualizado   = null;

        try {
            DB::transaction(function () use (
                $horario,
                $accion,
                $idUsuario,
                $campos,
                $observaciones,
                $motivo,
                &$estadoAnteriorNombre,
                &$estadoNuevoNombre,
                &$horarioActualizado,
            ) {
                // ── 1. Bloquear y recargar la fila real ─────────────
                $horarioBloqueado = Horario::where('id_horario', $horario->id_horario)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ── 2. Obtener nombre del estado actual ──────────────
                $estadoActualNombre = DB::table('estado_horario')
                    ->where('id_estado_horario', $horarioBloqueado->id_estado_horario)
                    ->value('nombre_estado');

                if (! $estadoActualNombre) {
                    throw new \RuntimeException(
                        "Estado de horario con id={$horarioBloqueado->id_estado_horario} no encontrado."
                    );
                }

                // ── 3. Validar transición ────────────────────────────
                $transicionesDesdeEstado = self::TRANSICIONES[$estadoActualNombre] ?? [];
                $estadoDestinoNombre     = $transicionesDesdeEstado[$accion] ?? null;

                if ($estadoDestinoNombre === null) {
                    $posibles = empty($transicionesDesdeEstado)
                        ? 'ninguna (estado terminal o sin transiciones definidas)'
                        : implode(', ', array_keys($transicionesDesdeEstado));

                    throw new HorarioStateException(
                        tipo:    'transicion_invalida',
                        mensaje: "No se puede ejecutar '{$accion}' desde el estado '{$estadoActualNombre}'. "
                               . "Transiciones posibles desde este estado: {$posibles}.",
                    );
                }

                // ── 4. Verificar que el horario tiene detalles activos ─
                // No tiene sentido aprobar, bloquear o publicar un horario
                // vacío. Se verifica con lockForUpdate para que la lectura
                // sea consistente con el bloqueo del horario ya adquirido.
                $tieneDetalles = \App\Models\DetalleHorario::where('id_horario', $horarioBloqueado->id_horario)
                    ->where('estado', 'activo')
                    ->lockForUpdate()
                    ->exists();

                if (! $tieneDetalles) {
                    throw new HorarioStateException(
                        tipo:    'horario_sin_detalles',
                        mensaje: 'No se puede cambiar el estado del horario porque no tiene detalles activos.',
                    );
                }

                // ── 5. Obtener id del estado destino ─────────────────
                $idEstadoDestino = DB::table('estado_horario')
                    ->where('nombre_estado', $estadoDestinoNombre)
                    ->value('id_estado_horario');

                if (! $idEstadoDestino) {
                    throw new \RuntimeException(
                        "Estado destino '{$estadoDestinoNombre}' no encontrado en la BD. Verifique los seeders."
                    );
                }

                // ── 6. UPDATE horario ────────────────────────────────
                $camposUpdate = array_merge(
                    ['id_estado_horario' => $idEstadoDestino],
                    $campos,
                );

                if ($observaciones !== null) {
                    $camposUpdate['observaciones'] = $observaciones;
                }

                $valorAnterior = [
                    'id_estado_horario'  => $horarioBloqueado->id_estado_horario,
                    'nombre_estado'      => $estadoActualNombre,
                    'fecha_aprobacion'   => $horarioBloqueado->fecha_aprobacion?->toDateTimeString(),
                    'fecha_bloqueo'      => $horarioBloqueado->fecha_bloqueo?->toDateTimeString(),
                    'observaciones'      => $horarioBloqueado->observaciones,
                ];

                $horarioBloqueado->update($camposUpdate);

                // ── 7. Historial ─────────────────────────────────────
                HistorialService::registrar(
                    tabla:         'horario',
                    idRegistro:    $horarioBloqueado->id_horario,
                    tipoCambio:    $this->tipoCambioHistorial($accion),
                    valorAnterior: $valorAnterior,
                    valorNuevo: array_merge(
                        ['id_estado_horario' => $idEstadoDestino, 'nombre_estado' => $estadoDestinoNombre],
                        $camposUpdate,
                    ),
                    motivo:    $motivo,
                    idUsuario: $idUsuario,
                );

                $estadoAnteriorNombre = $estadoActualNombre;
                $estadoNuevoNombre    = $estadoDestinoNombre;
                $horarioActualizado   = $horarioBloqueado->fresh();
            });

        } catch (HorarioStateException $e) {
            return HorarioStateResultado::rechazado(
                tipo:    $e->tipo,
                mensaje: $e->mensaje,
            );
        } catch (\Throwable $e) {
            return HorarioStateResultado::rechazado(
                tipo:    'error_inesperado',
                mensaje: "Error al cambiar el estado del horario: {$e->getMessage()}",
            );
        }

        return HorarioStateResultado::exitoso(
            horario:        $horarioActualizado,
            estadoAnterior: $estadoAnteriorNombre,
            estadoNuevo:    $estadoNuevoNombre,
        );
    }

    // ── Helpers ─────────────────────────────────────────────────

    /**
     * Mapea la acción al tipo de cambio del historial.
     * Usa los valores del ENUM oficial de historial_cambios.
     */
    private function tipoCambioHistorial(string $accion): string
    {
        return match ($accion) {
            'aprobar'  => 'aprobacion',
            'bloquear' => 'bloqueo',
            'publicar' => 'update',    // No existe 'publicacion' en el ENUM oficial
            default    => 'update',
        };
    }

    // ── Consulta de transiciones disponibles ────────────────────

    /**
     * Retorna las acciones disponibles desde el estado actual del horario.
     * Útil para el frontend para habilitar/deshabilitar botones.
     *
     * @param  string $nombreEstado  Nombre del estado actual
     * @return string[]              Lista de acciones disponibles (puede estar vacía)
     */
    public static function accionesDisponibles(string $nombreEstado): array
    {
        return array_keys(self::TRANSICIONES[$nombreEstado] ?? []);
    }

    /**
     * Retorna true si la transición es válida sin necesidad de instanciar el servicio.
     */
    public static function transicionEsValida(string $estadoActual, string $accion): bool
    {
        return isset(self::TRANSICIONES[$estadoActual][$accion]);
    }
}
