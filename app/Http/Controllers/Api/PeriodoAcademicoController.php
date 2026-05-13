<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PeriodoAcademico\StorePeriodoAcademicoRequest;
use App\Http\Requests\PeriodoAcademico\UpdatePeriodoAcademicoRequest;
use App\Models\PeriodoAcademico;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PeriodoAcademicoController extends Controller
{
    /**
     * GET /api/periodos-academicos
     */
    public function index(Request $request): JsonResponse
    {
        $query = PeriodoAcademico::query()
            ->when($request->estado, fn($q) => $q->where('estado', $request->estado))
            ->when($request->anio, fn($q) => $q->where('anio', $request->anio))
            ->when($request->es_vigente, fn($q) => $q->where('es_vigente', true))
            ->orderByDesc('anio')
            ->orderBy('numero_periodo');

        return response()->json($query->get());
    }

    /**
     * POST /api/periodos-academicos
     */
    public function store(StorePeriodoAcademicoRequest $request): JsonResponse
    {
        // Validar unicidad anio + numero_periodo (mensaje amigable)
        $existe = PeriodoAcademico::where('anio', $request->anio)
            ->where('numero_periodo', $request->numero_periodo)
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => "Ya existe un período académico para el año {$request->anio}, número {$request->numero_periodo}.",
                'errors'  => ['numero_periodo' => ["Período {$request->numero_periodo} del {$request->anio} ya registrado."]],
            ], 422);
        }

        $periodo = PeriodoAcademico::create([
            'nombre_periodo'                => $request->nombre_periodo,
            'anio'                          => $request->anio,
            'numero_periodo'                => $request->numero_periodo,
            'fecha_inicio'                  => $request->fecha_inicio,
            'fecha_fin'                     => $request->fecha_fin,
            'fecha_limite_edicion_horarios' => $request->fecha_limite_edicion_horarios,
            'estado'                        => $request->estado ?? 'planificacion',
            'es_vigente'                    => $request->es_vigente ?? false,
            'fecha_creacion'                => now(),
            'fecha_actualizacion'           => now(),
        ]);

        HistorialService::registrarCreacion($periodo, 'periodo_academico');

        return response()->json($periodo, 201);
    }

    /**
     * GET /api/periodos-academicos/{periodo}
     */
    public function show(int $id): JsonResponse
    {
        $periodo = PeriodoAcademico::findOrFail($id);
        return response()->json($periodo);
    }

    /**
     * PUT /api/periodos-academicos/{periodo}
     */
    public function update(UpdatePeriodoAcademicoRequest $request, int $id): JsonResponse
    {
        $periodo = PeriodoAcademico::findOrFail($id);

        // Si cambia anio o numero_periodo, verificar unicidad
        if ($request->has('anio') || $request->has('numero_periodo')) {
            $anio   = $request->anio          ?? $periodo->anio;
            $numero = $request->numero_periodo ?? $periodo->numero_periodo;

            $existe = PeriodoAcademico::where('anio', $anio)
                ->where('numero_periodo', $numero)
                ->where('id_periodo_academico', '!=', $id)
                ->exists();

            if ($existe) {
                return response()->json([
                    'message' => "Ya existe un período para el año {$anio}, número {$numero}.",
                ], 422);
            }
        }

        HistorialService::registrarActualizacion($periodo, 'periodo_academico');
        $periodo->update($request->only([
            'nombre_periodo', 'anio', 'numero_periodo',
            'fecha_inicio', 'fecha_fin', 'fecha_limite_edicion_horarios',
            'estado', 'es_vigente',
        ]));

        return response()->json($periodo);
    }

    /**
     * PATCH /api/periodos-academicos/{periodo}/marcar-vigente
     * Marca este período como vigente y desmarca los demás.
     */
    public function marcarVigente(int $id): JsonResponse
    {
        $periodo = PeriodoAcademico::findOrFail($id);

        DB::transaction(function () use ($periodo) {
            // Desmarcar todos
            PeriodoAcademico::where('es_vigente', true)->update(['es_vigente' => false]);
            // Marcar el seleccionado
            $periodo->update(['es_vigente' => true]);
        });

        HistorialService::registrar(
            tabla:     'periodo_academico',
            idRegistro: $periodo->id_periodo_academico,
            tipoCambio: 'update',
            valorNuevo: ['es_vigente' => true],
            motivo:    'Marcado como período vigente',
        );

        return response()->json([
            'message' => 'Período marcado como vigente.',
            'periodo' => $periodo->fresh(),
        ]);
    }

    /**
     * DELETE /api/periodos-academicos/{periodo}
     * Solo se puede desactivar si está en planificación y sin secciones.
     */
    public function destroy(int $id): JsonResponse
    {
        $periodo = PeriodoAcademico::findOrFail($id);

        if ($periodo->estado !== 'planificacion') {
            return response()->json([
                'message' => 'Solo se pueden eliminar períodos en estado planificación.',
            ], 422);
        }

        $tieneSecciones = $periodo->secciones()->exists();
        if ($tieneSecciones) {
            return response()->json([
                'message' => 'No se puede eliminar un período que ya tiene secciones registradas.',
            ], 422);
        }

        HistorialService::registrarEliminacion($periodo, 'periodo_academico');
        $periodo->update(['estado' => 'cerrado']);

        return response()->json(['message' => 'Período académico cerrado correctamente.']);
    }
}
