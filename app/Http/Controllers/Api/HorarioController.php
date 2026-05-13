<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Horario;
use App\Models\PeriodoAcademico;
use App\Services\Horario\EdicionManualService;
use App\Services\Horario\HorarioConsultaService;
use App\Services\Horario\HorarioStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HorarioController
 *
 * Cubre los endpoints de Sprint 3 — Paso 5 (edición manual).
 * Los endpoints de generación automática (Paso 3-4) se agregan aquí
 * en la integración final.
 *
 * Rutas:
 *   GET    /api/horarios/{horario}                              → show()
 *   GET    /api/horarios/{horario}/detalles                     → detalles()
 *   PATCH  /api/horarios/{horario}/detalles/{detalle}/mover     → moverDetalle()
 *   DELETE /api/horarios/{horario}/detalles/{detalle}           → eliminarDetalle()
 *   PATCH  /api/horarios/{horario}/aprobar                      → aprobar()  [solo admin]
 *   PATCH  /api/horarios/{horario}/bloquear                     → bloquear() [solo admin]
 *   PATCH  /api/horarios/{horario}/publicar                     → publicar() [solo admin]
 */
class HorarioController extends Controller
{
    public function __construct(
        private readonly EdicionManualService  $edicionService,
        private readonly HorarioStateService   $stateService,
        private readonly HorarioConsultaService $consultaService,
    ) {}

    /**
     * GET /api/horarios/{horario}
     * Retorna el horario con su estado y resumen de detalles.
     */
    public function show(int $idHorario): JsonResponse
    {
        $horario = Horario::with([
            'carrera',
            'periodoAcademico',
            'estadoHorario',
        ])->findOrFail($idHorario);

        $totalDetalles = $horario->detallesActivos()->count();

        return response()->json([
            'horario'        => $horario,
            'total_detalles' => $totalDetalles,
        ]);
    }

    /**
     * GET /api/horarios/{horario}/detalles
     * Lista los detalles activos de un horario con toda la información
     * necesaria para el coordinador: curso, docente, bloque, día, hora.
     */
    public function detalles(int $idHorario): JsonResponse
    {
        // Verificar que el horario existe
        $horario = Horario::findOrFail($idHorario);

        $detalles = \DB::table('detalle_horario as dh')
            ->join('asignacion_docente_curso as adc',
                'dh.id_asignacion_docente_curso', '=', 'adc.id_asignacion_docente_curso')
            ->join('docente as d',   'adc.id_docente',  '=', 'd.id_docente')
            ->join('usuario as u',   'd.id_usuario',    '=', 'u.id_usuario')
            ->join('seccion as s',   'adc.id_seccion',  '=', 's.id_seccion')
            ->join('curso as c',     's.id_curso',      '=', 'c.id_curso')
            ->join('bloque_horario as bh', 'dh.id_bloque_horario', '=', 'bh.id_bloque_horario')
            ->join('dia',            'dh.id_dia',       '=', 'dia.id_dia')
            ->where('dh.id_horario', $idHorario)
            ->where('dh.estado', 'activo')
            ->orderBy('dia.orden_semana')
            ->orderBy('bh.hora_inicio')
            ->select([
                'dh.id_detalle_horario',
                'dh.id_horario',
                'dh.id_bloque_horario',
                'dh.id_dia',
                'bh.hora_inicio',
                'bh.hora_fin',
                'bh.duracion_minutos',
                'dia.nombre_dia',
                'dia.orden_semana',
                'c.nombre_curso',
                'c.codigo_curso',
                's.numero_seccion',
                's.id_seccion',
                \DB::raw("CONCAT(u.nombres, ' ', u.apellidos) as nombre_docente"),
                'd.codigo_docente',
                'd.prioridad',
                'adc.id_asignacion_docente_curso',
            ])
            ->get();

        return response()->json([
            'id_horario' => $idHorario,
            'total'      => $detalles->count(),
            'detalles'   => $detalles,
        ]);
    }

    /**
     * PATCH /api/horarios/{horario}/detalles/{detalle}/mover
     *
     * Mueve una clase a un bloque distinto dentro del mismo horario.
     *
     * Body:
     *   { "id_bloque_horario": 12 }
     *
     * Validaciones aplicadas (via validarParaEdicionManual):
     *   - Fecha límite del período
     *   - Estado del horario (borrador/generado solamente)
     *   - Disponibilidad docente en el nuevo bloque
     *   - Docente no ocupado globalmente en el nuevo bloque
     *   - Ciclo_semestre sin traslape en el nuevo bloque
     *   - Bloque destino no ocupado en este horario (excluye el propio detalle)
     */
    public function moverDetalle(Request $request, int $idHorario, int $idDetalle): JsonResponse
    {
        $request->validate([
            'id_bloque_horario' => ['required', 'integer', 'exists:bloque_horario,id_bloque_horario'],
        ]);

        $horario = Horario::with('estadoHorario')->findOrFail($idHorario);
        $periodo = PeriodoAcademico::findOrFail($horario->id_periodo_academico);

        $resultado = $this->edicionService->moverDetalle(
            idDetalle:     $idDetalle,
            idBloqueNuevo: $request->id_bloque_horario,
            horario:       $horario,
            periodo:       $periodo,
            idUsuario:     $request->user()->id_usuario,
        );

        if (! $resultado->exitoso) {
            $status = match ($resultado->tipo) {
                'fecha_limite_vencida', 'horario_no_editable' => 422,
                'detalle_no_encontrado', 'bloque_no_encontrado', 'asignacion_no_encontrada' => 404,
                'conflicto_validacion' => 409,
                default => 500,
            };
            return response()->json($resultado->toArray(), $status);
        }

        return response()->json($resultado->toArray());
    }

    /**
     * DELETE /api/horarios/{horario}/detalles/{detalle}
     *
     * Elimina lógicamente una clase del horario.
     * La sección queda sin bloque asignado; la asignación docente-sección
     * permanece intacta.
     *
     * Body (opcional):
     *   { "motivo": "El docente solicitó cambio de sección" }
     */
    public function eliminarDetalle(Request $request, int $idHorario, int $idDetalle): JsonResponse
    {
        $request->validate([
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        $horario = Horario::with('estadoHorario')->findOrFail($idHorario);
        $periodo = PeriodoAcademico::findOrFail($horario->id_periodo_academico);

        $resultado = $this->edicionService->eliminarDetalle(
            idDetalle:  $idDetalle,
            horario:    $horario,
            periodo:    $periodo,
            idUsuario:  $request->user()->id_usuario,
            motivo:     $request->motivo,
        );

        if (! $resultado->exitoso) {
            $status = match ($resultado->tipo) {
                'fecha_limite_vencida', 'horario_no_editable' => 422,
                'detalle_no_encontrado' => 404,
                default => 500,
            };
            return response()->json($resultado->toArray(), $status);
        }

        return response()->json($resultado->toArray());
    }

    /**
     * PATCH /api/horarios/{horario}/aprobar
     *
     * Aprueba el horario. Solo para administradores.
     * Transición válida: generado → aprobado
     *
     * Body (opcional):
     *   { "observaciones": "Revisado y aprobado por decanatura" }
     */
    public function aprobar(Request $request, int $idHorario): JsonResponse
    {
        $request->validate([
            'observaciones' => ['nullable', 'string', 'max:200'],
        ]);

        $horario = Horario::findOrFail($idHorario);

        $resultado = $this->stateService->aprobar(
            horario:       $horario,
            idUsuario:     $request->user()->id_usuario,
            observaciones: $request->observaciones,
        );

        if (! $resultado->exitoso) {
            $status = $resultado->tipo === 'transicion_invalida' ? 422 : 500;
            return response()->json($resultado->toArray(), $status);
        }

        return response()->json($resultado->toArray());
    }

    /**
     * PATCH /api/horarios/{horario}/bloquear
     *
     * Bloquea el horario. Solo para administradores.
     * Transición válida: aprobado → bloqueado
     *
     * Body (opcional):
     *   { "observaciones": "Bloqueado por revisión de cambios de último momento" }
     */
    public function bloquear(Request $request, int $idHorario): JsonResponse
    {
        $request->validate([
            'observaciones' => ['nullable', 'string', 'max:200'],
        ]);

        $horario = Horario::findOrFail($idHorario);

        $resultado = $this->stateService->bloquear(
            horario:       $horario,
            idUsuario:     $request->user()->id_usuario,
            observaciones: $request->observaciones,
        );

        if (! $resultado->exitoso) {
            $status = $resultado->tipo === 'transicion_invalida' ? 422 : 500;
            return response()->json($resultado->toArray(), $status);
        }

        return response()->json($resultado->toArray());
    }

    /**
     * PATCH /api/horarios/{horario}/publicar
     *
     * Publica el horario. Solo para administradores.
     * Transiciones válidas: aprobado → publicado, bloqueado → publicado
     * Estado terminal — no tiene salida posterior.
     *
     * Body (opcional):
     *   { "observaciones": "Publicado para el período 2024-1" }
     */
    public function publicar(Request $request, int $idHorario): JsonResponse
    {
        $request->validate([
            'observaciones' => ['nullable', 'string', 'max:200'],
        ]);

        $horario = Horario::findOrFail($idHorario);

        $resultado = $this->stateService->publicar(
            horario:       $horario,
            idUsuario:     $request->user()->id_usuario,
            observaciones: $request->observaciones,
        );

        if (! $resultado->exitoso) {
            $status = $resultado->tipo === 'transicion_invalida' ? 422 : 500;
            return response()->json($resultado->toArray(), $status);
        }

        return response()->json($resultado->toArray());
    }

    /**
     * GET /api/horarios/{horario}/transiciones
     *
     * Retorna las acciones disponibles desde el estado actual.
     * Útil para el frontend (habilitar/deshabilitar botones de estado).
     */
    public function transicionesDisponibles(int $idHorario): JsonResponse
    {
        $horario = Horario::with('estadoHorario')->findOrFail($idHorario);

        $nombreEstado = $horario->estadoHorario?->nombre_estado ?? '';
        $acciones     = HorarioStateService::accionesDisponibles($nombreEstado);

        return response()->json([
            'id_horario'    => $idHorario,
            'estado_actual' => $nombreEstado,
            'acciones'      => $acciones,
            'es_terminal'   => empty($acciones),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sprint 4 — Paso 1: Endpoints de consulta
    // ═══════════════════════════════════════════════════════════════

    /**
     * GET /api/horarios
     * Lista horarios accesibles para admin o coordinador.
     * Filtros opcionales: ?id_carrera=X &id_periodo_academico=Y &estado=publicado
     *
     * Admin:       todos los horarios.
     * Coordinador: solo las carreras que coordina.
     */
    public function index(Request $request): JsonResponse
    {
        $idCoord = $this->idCoordinadorSiEsCoord($request);

        $horarios = $this->consultaService->listar(
            idUsuarioCoordinador: $idCoord,
            idCarreraFiltro:      $request->integer('id_carrera') ?: null,
            idPeriodoFiltro:      $request->integer('id_periodo_academico') ?: null,
            estadoFiltro:         $request->string('estado')->value() ?: null,
        );

        return response()->json([
            'total'    => count($horarios),
            'horarios' => $horarios,
        ]);
    }

    /**
     * GET /api/horarios/por-carrera?id_carrera=X&id_periodo_academico=Y
     * Horarios de una carrera y período específicos.
     *
     * Coordinador: solo si coordina esa carrera.
     */
    public function porCarrera(Request $request): JsonResponse
    {
        $request->validate([
            'id_carrera'           => ['required', 'integer', 'exists:carrera,id_carrera'],
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
        ]);

        $idCoord = $this->idCoordinadorSiEsCoord($request);

        $horarios = $this->consultaService->porCarreraYPeriodo(
            idCarrera:             (int) $request->id_carrera,
            idPeriodo:             (int) $request->id_periodo_academico,
            idUsuarioCoordinador:  $idCoord,
        );

        return response()->json([
            'total'    => count($horarios),
            'horarios' => $horarios,
        ]);
    }

    /**
     * GET /api/horarios/{horario}/completo
     * Detalles enriquecidos: curso, sección, docente, día, hora,
     * jornada, carrera, ciclo_semestre (resuelto por pensum de la carrera).
     *
     * Coordinador: solo si coordina la carrera del horario.
     */
    public function completo(Request $request, int $idHorario): JsonResponse
    {
        $idCoord = $this->idCoordinadorSiEsCoord($request);

        $data = $this->consultaService->detallesCompletos(
            idHorario:            $idHorario,
            idUsuarioCoordinador: $idCoord,
        );

        if (empty($data)) {
            return response()->json(['message' => 'Horario no encontrado.'], 404);
        }

        return response()->json($data);
    }

    /**
     * GET /api/docente/horario
     * Clases del docente autenticado en todos sus horarios activos.
     * Fuera del namespace horarios/* para no colisionar con horarios/{horario}.
     * El docente no puede consultar clases de otros docentes.
     */
    public function miHorario(Request $request): JsonResponse
    {
        $docente = $request->user()->docente()->first();

        if (! $docente) {
            return response()->json(['message' => 'Perfil docente no encontrado.'], 404);
        }

        $clases = $this->consultaService->porDocente($docente->id_docente);

        return response()->json([
            'id_docente' => $docente->id_docente,
            'total'      => count($clases),
            'clases'     => $clases,
        ]);
    }

    /**
     * GET /api/estudiante/horario?id_carrera=X&id_periodo_academico=Y
     * Horario publicado para el rol estudiante.
     * Fuera del namespace horarios/* para no colisionar con horarios/{horario}.
     *
     * Sin tabla estudiante_carrera en el modelo oficial: el estudiante
     * indica la carrera y período que desea consultar.
     * Solo devuelve horarios en estado 'publicado'.
     */
    public function estudianteHorario(Request $request): JsonResponse
    {
        $request->validate([
            'id_carrera'           => ['required', 'integer', 'exists:carrera,id_carrera'],
            'id_periodo_academico' => ['required', 'integer', 'exists:periodo_academico,id_periodo_academico'],
        ]);

        $data = $this->consultaService->publicadoPorCarreraYPeriodo(
            idCarrera: (int) $request->id_carrera,
            idPeriodo: (int) $request->id_periodo_academico,
        );

        return response()->json($data);
    }

    // ── Helper de permisos ──────────────────────────────────────

    /**
     * Si el usuario autenticado tiene el perfil activo 'coordinador',
     * retorna su id_usuario para que el servicio filtre sus carreras.
     * Si es admin, retorna null (sin restricción).
     */
    private function idCoordinadorSiEsCoord(Request $request): ?int
    {
        $usuario = $request->user();
        // ultimo_perfil_activo es varchar(100) con el nombre del rol
        if ($usuario->ultimo_perfil_activo === 'coordinador') {
            return $usuario->id_usuario;
        }
        return null;
    }
}
