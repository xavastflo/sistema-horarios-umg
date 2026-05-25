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
     * La validación cruzada de jornada se aplica AQUÍ, en el controlador,
     * antes de llamar al service. Esto garantiza que ningún bloque se persiste
     * si las horas o días no corresponden a la jornada seleccionada.
     *
     * Reglas institucionales:
     *   Matutina      → 06:00–18:00, Lunes–Viernes
     *   Vespertina    → 18:00–22:00, Lunes–Viernes
     *   Fin de Semana → 06:00–18:00, Sábado o Domingo
     */
    public function generar(GenerarBloquesRequest $request): JsonResponse
    {
        // ── 1. Resolver jornada y sus restricciones ───────────────
        $carreraJornada = CarreraJornada::with('jornada')
            ->where('id_carrera_jornada', $request->id_carrera_jornada)
            ->where('estado', 'activo')
            ->firstOrFail();

        $nombreJornada = $carreraJornada->jornada?->nombre_jornada;

        // ── 2. Validación cruzada por jornada ────────────────────────
        // Limpieza de horas a HH:MM exacto (substr descarta segundos de MySQL)
        $hi   = substr($request->hora_inicio_general, 0, 5);
        $hf   = substr($request->hora_fin_general,    0, 5);
        $dias = $request->ids_dia ?? [];

        // Comparación en minúsculas — nombre_jornada en BD: 'matutina','vespertina','fin de semana'
        $jornada = strtolower(trim($nombreJornada ?? ''));

        if ($jornada === 'matutina') {
            // Matutina: 06:00–18:00, Lunes–Viernes (ids 1–5)
            $tieneFinDeSemana = in_array(6, $dias) || in_array(7, $dias);
            if ($hi < '06:00' || $hi > '18:00' || $hf < '06:00' || $hf > '18:00' || $tieneFinDeSemana) {
                return response()->json([
                    'message' => 'Error de validación institucional.',
                    'errors'  => ['hora_inicio_general' => [
                        'Para la jornada Matutina, el horario debe ser de Lunes a Viernes entre las 06:00 AM y las 06:00 PM.',
                    ]],
                ], 422);
            }
        }

        if ($jornada === 'vespertina') {
            // Vespertina: 18:00–22:00, Lunes–Viernes (ids 1–5)
            $tieneFinDeSemana = in_array(6, $dias) || in_array(7, $dias);
            if ($hi < '18:00' || $hi > '22:00' || $hf < '18:00' || $hf > '22:00' || $tieneFinDeSemana) {
                return response()->json([
                    'message' => 'Error de validación institucional.',
                    'errors'  => ['hora_inicio_general' => [
                        'Para la jornada Vespertina, el horario debe ser de Lunes a Viernes entre las 06:00 PM y las 10:00 PM.',
                    ]],
                ], 422);
            }
        }

        if ($jornada === 'fin de semana') {
            // Fin de Semana: 06:00–18:00, SOLO Sábado o Domingo (ids 6 y 7)
            $tieneDiaEntreSemana = false;
            foreach ([1, 2, 3, 4, 5] as $d) {
                if (in_array($d, $dias)) {
                    $tieneDiaEntreSemana = true;
                    break;
                }
            }
            if ($hi < '06:00' || $hi > '18:00' || $hf < '06:00' || $hf > '18:00' || $tieneDiaEntreSemana) {
                return response()->json([
                    'message' => 'Error de validación institucional.',
                    'errors'  => ['hora_inicio_general' => [
                        'Para la jornada Fin de Semana, el horario debe ser de Sábado o Domingo entre las 06:00 AM y las 06:00 PM.',
                    ]],
                ], 422);
            }
        }

        // ── 3. Generar bloques (solo si pasó la validación) ──────────
        $resultado = $this->bloqueService->generarBloques(
            idCarreraJornada:  $request->id_carrera_jornada,
            idsDia:            $request->ids_dia,
            horaInicioGeneral: $request->hora_inicio_general,
            horaFinGeneral:    $request->hora_fin_general,
            duracionMinutos:   $request->duracion_minutos,
            exclusiones:       $request->exclusiones ?? [],
        );

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
