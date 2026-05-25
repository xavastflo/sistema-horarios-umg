<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CarreraJornada;
use App\Models\EstadoHorario;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use App\Services\Horario\GeneradorParcialService;
use App\Services\Horario\PersistenciaHorarioService;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GeneracionHorarioController
 *
 * Expone la generación automática de horarios por API.
 * Orquesta GeneradorParcialService y PersistenciaHorarioService
 * sin duplicar su lógica interna.
 *
 * Ruta registrada:
 *   POST /api/horarios/generar    (rol: administrador, coordinador)
 *
 * Flujo:
 *   1. Validar request (id_periodo_academico, id_carrera_jornada)
 *   2. Derivar id_carrera desde CarreraJornada
 *   3. Buscar o crear Horario (estado borrador)
 *   4. Si ya tiene detalles activos → limpiarDetalles() (regeneración)
 *   5. GeneradorParcialService::generar()
 *   6. PersistenciaHorarioService::confirmar()
 *   7. Devolver resumen
 */
class GeneracionHorarioController extends Controller
{
    public function __construct(
        private readonly GeneradorParcialService   $generador,
        private readonly PersistenciaHorarioService $persistencia,
    ) {}

    /**
     * POST /api/horarios/generar
     *
     * Body:
     *   {
     *     "id_periodo_academico": 1,
     *     "id_carrera_jornada":   1
     *   }
     *
     * Respuesta exitosa (200):
     *   {
     *     "message":  "Horario generado correctamente.",
     *     "horario":  { id_horario, id_carrera, id_periodo_academico, estado_horario },
     *     "resumen":  { "asignadas": N, "no_asignadas": M, "detalles_insertados": N },
     *     "no_asignadas": [ ... ]   // secciones que no pudieron asignarse
     *   }
     */
    public function generar(Request $request): JsonResponse
    {
        // ── 1. Validar request ────────────────────────────────────────
        $request->validate([
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
            'id_carrera_jornada'   => ['required', 'integer', 'exists:carrera_jornada,id_carrera_jornada'],
        ]);

        $idPeriodo       = (int) $request->id_periodo_academico;
        $idCarreraJornada = (int) $request->id_carrera_jornada;

        // ── 2. Cargar modelos necesarios ───────────────────────────────
        $periodo       = PeriodoAcademico::findOrFail($idPeriodo);
        $carreraJornada = CarreraJornada::with('carrera')->findOrFail($idCarreraJornada);
        $idCarrera     = $carreraJornada->id_carrera;

        // ── 3. Verificar que haya bloques activos para esa carrera_jornada ─
        $tieneBloques = DB::table('bloque_horario')
            ->where('id_carrera_jornada', $idCarreraJornada)
            ->where('estado', 'activo')
            ->exists();

        if (! $tieneBloques) {
            return response()->json([
                'message' => 'No existen bloques horarios activos para la carrera-jornada seleccionada. '
                           . 'Genera los bloques primero desde el módulo de Bloques Horarios.',
            ], 422);
        }

        // ── 4. Verificar que haya secciones con docente asignado en esta jornada ──
        // Filtro directo por id_carrera_jornada (reingeniería estructural)
        $tieneSecciones = DB::table('asignacion_docente_curso as adc')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->where('s.id_carrera_jornada',   $idCarreraJornada)
            ->where('s.id_periodo_academico', $idPeriodo)
            ->where('adc.estado', 'activo')
            ->where('s.estado',   'activo')
            ->exists();

        if (! $tieneSecciones) {
            return response()->json([
                'message' => 'No existen secciones activas con docente asignado para esta carrera y período. '
                           . 'Asigna docentes a las secciones antes de generar el horario.',
            ], 422);
        }

        // ── 5. Buscar o crear el Horario (estado borrador) ─────────────
        $idEstadoBorrador = DB::table('estado_horario')
            ->where('nombre_estado', EstadoHorario::BORRADOR)
            ->value('id_estado_horario');

        if (! $idEstadoBorrador) {
            return response()->json([
                'message' => "Estado 'borrador' no encontrado. Verifique los seeders.",
            ], 500);
        }

        $horario = Horario::firstOrCreate(
            [
                'id_carrera'           => $idCarrera,
                'id_periodo_academico' => $idPeriodo,
            ],
            [
                'id_estado_horario' => $idEstadoBorrador,
                'fecha_creacion'    => now(),
                'fecha_actualizacion' => now(),
            ]
        );

        // ── 6. Si ya tiene detalles activos para ESTA JORNADA → limpiar (regeneración selectiva)
        $tieneDetallesJornada = DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc', 'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('seccion as s', 'adc.id_seccion', '=', 's.id_seccion')
            ->where('dh.id_horario', $horario->id_horario)
            ->where('dh.estado', 'activo')
            ->where('s.id_carrera_jornada', $idCarreraJornada)
            ->exists();

        if ($tieneDetallesJornada) {
            try {
                $borrados = $this->persistencia->limpiarDetalles(
                    horario:          $horario,
                    idCarreraJornada: $idCarreraJornada,
                    idUsuario:        $request->user()->id_usuario,
                );
            } catch (\RuntimeException $e) {
                return response()->json([
                    'message' => 'No se puede regenerar: ' . $e->getMessage(),
                ], 422);
            }
        }

        // Recargar el horario actualizado
        $horario->refresh();

        // ── 7. Generar propuesta ───────────────────────────────────────
        $resultado = $this->generador->generar(
            horario:          $horario,
            periodo:          $periodo,
            idCarreraJornada: $idCarreraJornada,
        );

        // Si el generador devuelve 0 propuestas, no hay nada que persistir
        if ($resultado->asignacionesPropuestas()->isEmpty()) {
            return response()->json([
                'message'      => 'El generador no produjo asignaciones. Verifique bloques, disponibilidad y secciones.',
                'no_asignadas' => $resultado->seccionesNoAsignables()->map->toArray()->values(),
                'resumen'      => $resultado->estadisticas(),
            ], 422);
        }

        // ── 8. Persistir ───────────────────────────────────────────────
        $persistido = $this->persistencia->confirmar(
            resultado: $resultado,
            horario:   $horario,
            periodo:   $periodo,
            idUsuario: $request->user()->id_usuario,
        );

        if (! $persistido->exitoso) {
            return response()->json([
                'message'  => $persistido->mensaje,
                'detalle'  => $persistido->toArray(),
            ], 422);
        }

        // ── 9. Registrar evento de generación en historial ─────────────
        HistorialService::registrar(
            tabla:      'horario',
            idRegistro: $horario->id_horario,
            tipoCambio: 'update',
            valorNuevo: [
                'accion'               => 'generacion_automatica',
                'id_carrera'           => $idCarrera,
                'id_carrera_jornada'   => $idCarreraJornada,
                'id_periodo_academico' => $idPeriodo,
                'asignadas'            => $resultado->totalAsignadas(),
                'no_asignadas'         => $resultado->totalNoAsignadas(),
                'detalles_insertados'  => $persistido->detallesInsertados,
            ],
            motivo:    "Generación automática por jornada {$idCarreraJornada}: "
                     . "{$persistido->detallesInsertados} detalles insertados.",
            idUsuario: $request->user()->id_usuario,
        );

        // ── 10. Respuesta final ────────────────────────────────────────
        $horario->refresh();
        $horario->load(['carrera', 'periodoAcademico', 'estadoHorario']);

        return response()->json([
            'message' => 'Horario generado correctamente.',
            'horario' => [
                'id_horario'           => $horario->id_horario,
                'id_carrera'           => $horario->id_carrera,
                'id_periodo_academico' => $horario->id_periodo_academico,
                'estado_horario'       => $horario->estadoHorario?->nombre_estado,
                'carrera'              => $horario->carrera?->only(['id_carrera', 'nombre_carrera', 'codigo_carrera']),
                'periodo_academico'    => $horario->periodoAcademico?->only(['id_periodo_academico', 'nombre_periodo', 'anio']),
            ],
            'resumen' => [
                'asignadas'          => $resultado->totalAsignadas(),
                'no_asignadas'       => $resultado->totalNoAsignadas(),
                'detalles_insertados'=> $persistido->detallesInsertados,
            ],
            'no_asignadas' => $resultado->seccionesNoAsignables()->map->toArray()->values(),
        ]);
    }
}
