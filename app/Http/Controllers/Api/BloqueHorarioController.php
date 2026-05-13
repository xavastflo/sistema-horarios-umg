<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\BloqueHorario\StoreBloqueHorarioRequest;
use App\Http\Requests\BloqueHorario\GenerarBloquesRequest;
use App\Models\BloqueHorario;
use App\Models\CarreraJornada;
use App\Services\BloqueHorarioService;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BloqueHorarioController extends Controller
{
    public function __construct(
        private readonly BloqueHorarioService $bloqueService
    ) {}

    /**
     * GET /api/bloques-horario
     * Filtros: id_carrera_jornada, id_dia, estado
     */
    public function index(Request $request): JsonResponse
    {
        $query = BloqueHorario::with(['carreraJornada.carrera', 'carreraJornada.jornada', 'dia'])
            ->when($request->id_carrera_jornada, fn($q) => $q->where('id_carrera_jornada', $request->id_carrera_jornada))
            ->when($request->id_dia, fn($q) => $q->where('id_dia', $request->id_dia))
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->orderBy('id_dia')
            ->orderBy('hora_inicio');

        return response()->json($query->get());
    }

    /**
     * POST /api/bloques-horario
     * Crea un bloque individual manualmente.
     */
    public function store(StoreBloqueHorarioRequest $request): JsonResponse
    {
        // Verificar duplicado con mensaje amigable
        $existe = BloqueHorario::where('id_carrera_jornada', $request->id_carrera_jornada)
            ->where('id_dia', $request->id_dia)
            ->where('hora_inicio', $request->hora_inicio)
            ->where('hora_fin', $request->hora_fin)
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'Ya existe un bloque horario con esa combinación de carrera-jornada, día y horario.',
                'errors'  => ['hora_inicio' => ['Bloque duplicado.']],
            ], 422);
        }

        $bloque = BloqueHorario::create([
            'id_carrera_jornada'  => $request->id_carrera_jornada,
            'id_dia'              => $request->id_dia,
            'hora_inicio'         => $request->hora_inicio,
            'hora_fin'            => $request->hora_fin,
            'duracion_minutos'    => $request->duracion_minutos,
            'estado'              => 'activo',
            'fecha_creacion'      => now(),
            'fecha_actualizacion' => now(),
        ]);

        HistorialService::registrarCreacion($bloque, 'bloque_horario');

        return response()->json($bloque->load(['dia', 'carreraJornada']), 201);
    }

    /**
     * GET /api/bloques-horario/{bloque}
     */
    public function show(int $id): JsonResponse
    {
        $bloque = BloqueHorario::with(['carreraJornada.carrera', 'carreraJornada.jornada', 'dia'])
            ->findOrFail($id);

        return response()->json($bloque);
    }

    /**
     * DELETE /api/bloques-horario/{bloque}
     * Soft delete — no eliminar si el bloque está en un detalle de horario activo.
     */
    public function destroy(int $id): JsonResponse
    {
        $bloque = BloqueHorario::findOrFail($id);

        // Verificar que no esté en uso en algún detalle de horario activo
        $enUso = \DB::table('detalle_horario')
            ->where('id_bloque_horario', $id)
            ->where('estado', 'activo')
            ->exists();

        if ($enUso) {
            return response()->json([
                'message' => 'No se puede desactivar un bloque que está en uso en un horario activo.',
            ], 422);
        }

        HistorialService::registrarEliminacion($bloque, 'bloque_horario');
        $bloque->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Bloque horario desactivado correctamente.']);
    }

    /**
     * POST /api/bloques-horario/generar
     * Genera bloques automáticamente para una carrera-jornada en uno o varios días.
     *
     * Ejemplo vespertina 18:00-21:00, duración 90 min → 18:00-19:30, 19:30-21:00
     * Ejemplo sábado 07:00-16:00, exclusión 13:00-14:00, duración 120 min →
     *   07:00-09:00, 09:00-11:00, 11:00-13:00, 14:00-16:00
     */
    public function generar(GenerarBloquesRequest $request): JsonResponse
    {
        // Verificar que la carrera_jornada existe y está activa
        $carreraJornada = CarreraJornada::where('id_carrera_jornada', $request->id_carrera_jornada)
            ->where('estado', 'activo')
            ->firstOrFail();

        $resultado = $this->bloqueService->generarBloques(
            idCarreraJornada: $request->id_carrera_jornada,
            idsDia:           $request->ids_dia,
            horaInicioGeneral: $request->hora_inicio_general,
            horaFinGeneral:   $request->hora_fin_general,
            duracionMinutos:  $request->duracion_minutos,
            exclusiones:      $request->exclusiones ?? [],
        );

        // Registrar en historial por cada bloque creado
        foreach ($resultado['creados'] as $bloque) {
            HistorialService::registrarCreacion($bloque, 'bloque_horario');
        }

        return response()->json([
            'message'       => "Se generaron {$resultado['total_creados']} bloques correctamente.",
            'total_creados' => $resultado['total_creados'],
            'creados'       => $resultado['creados'],
            'omitidos'      => $resultado['omitidos'],
        ], 201);
    }

    /**
     * GET /api/carrera-jornadas/{carreraJornada}/bloques
     * Bloques de una carrera-jornada específica, organizados por día.
     */
    public function porCarreraJornada(int $idCarreraJornada): JsonResponse
    {
        $carreraJornada = CarreraJornada::with(['carrera', 'jornada'])->findOrFail($idCarreraJornada);

        $bloques = BloqueHorario::with('dia')
            ->where('id_carrera_jornada', $idCarreraJornada)
            ->where('estado', 'activo')
            ->orderBy('id_dia')
            ->orderBy('hora_inicio')
            ->get()
            ->groupBy('id_dia');

        return response()->json([
            'carrera_jornada' => $carreraJornada,
            'bloques_por_dia' => $bloques,
        ]);
    }
}
