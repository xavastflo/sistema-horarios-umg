<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DisponibilidadDocente\StoreDisponibilidadRequest;
use App\Models\BloqueHorario;
use App\Models\Docente;
use App\Models\DisponibilidadDocente;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DisponibilidadDocenteController extends Controller
{
    /**
     * GET /api/docentes/{docente}/disponibilidad
     * Lista los bloques marcados como NO disponibles para el docente.
     * Si no existe registro para un bloque, el docente SÍ está disponible.
     */
    public function index(int $idDocente): JsonResponse
    {
        $docente = Docente::findOrFail($idDocente);

        $disponibilidades = DisponibilidadDocente::with(['bloqueHorario.dia', 'bloqueHorario.carreraJornada.jornada'])
            ->where('id_docente', $idDocente)
            ->where('estado', 'activo')
            ->orderBy('id_bloque_horario')
            ->get();

        return response()->json([
            'docente'             => [
                'id_docente'     => $docente->id_docente,
                'codigo_docente' => $docente->codigo_docente,
            ],
            'bloques_no_disponibles' => $disponibilidades,
            'total'                  => $disponibilidades->count(),
        ]);
    }

    /**
     * POST /api/docentes/{docente}/disponibilidad
     * Marca un bloque como NO disponible para el docente.
     * REGLA: Si existe registro → no disponible. Si no existe → disponible.
     */
    public function store(StoreDisponibilidadRequest $request, int $idDocente): JsonResponse
    {
        $docente = Docente::where('id_docente', $idDocente)
            ->where('estado', 'activo')
            ->firstOrFail();

        // Verificar que el bloque existe y está activo
        $bloque = BloqueHorario::where('id_bloque_horario', $request->id_bloque_horario)
            ->where('estado', 'activo')
            ->firstOrFail();

        // Verificar si ya existe el registro (activo o inactivo)
        $existente = DisponibilidadDocente::where('id_docente', $idDocente)
            ->where('id_bloque_horario', $request->id_bloque_horario)
            ->first();

        if ($existente) {
            if ($existente->estado === 'activo') {
                return response()->json([
                    'message' => 'El docente ya tiene ese bloque marcado como no disponible.',
                ], 422);
            }
            // Reactivar si estaba inactivo
            $existente->update([
                'estado'              => 'activo',
                'observacion'         => $request->observacion,
                'fecha_actualizacion' => now(),
            ]);
            $disponibilidad = $existente;
        } else {
            $disponibilidad = DisponibilidadDocente::create([
                'id_docente'          => $idDocente,
                'id_bloque_horario'   => $request->id_bloque_horario,
                'observacion'         => $request->observacion,
                'estado'              => 'activo',
                'fecha_registro'      => now(),
                'fecha_actualizacion' => now(),
            ]);
        }

        HistorialService::registrar(
            tabla:      'disponibilidad_docente',
            idRegistro: $disponibilidad->id_disponibilidad_docente,
            tipoCambio: 'insert',
            valorNuevo: [
                'id_docente'        => $idDocente,
                'id_bloque_horario' => $request->id_bloque_horario,
                'observacion'       => $request->observacion,
            ],
            motivo: 'Docente marcó bloque como no disponible',
        );

        return response()->json([
            'message'       => 'Bloque marcado como no disponible.',
            'disponibilidad' => $disponibilidad->load('bloqueHorario.dia'),
        ], 201);
    }

    /**
     * DELETE /api/docentes/{docente}/disponibilidad/{disponibilidad}
     * Desmarca un bloque: el docente vuelve a estar disponible en ese horario.
     */
    public function destroy(int $idDocente, int $idDisponibilidad): JsonResponse
    {
        $disponibilidad = DisponibilidadDocente::where('id_docente', $idDocente)
            ->where('id_disponibilidad_docente', $idDisponibilidad)
            ->where('estado', 'activo')
            ->firstOrFail();

        HistorialService::registrar(
            tabla:         'disponibilidad_docente',
            idRegistro:    $disponibilidad->id_disponibilidad_docente,
            tipoCambio:    'delete',
            valorAnterior: $disponibilidad->toArray(),
            motivo:        'Docente desmarcó bloque — vuelve a estar disponible',
        );

        $disponibilidad->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Bloque desmarcado. El docente ahora está disponible en ese horario.']);
    }

    /**
     * POST /api/docentes/{docente}/disponibilidad/toggle
     * Marca o desmarca un bloque en una sola llamada (útil para interfaces de calendario).
     */
    public function toggle(Request $request, int $idDocente): JsonResponse
    {
        $request->validate([
            'id_bloque_horario' => ['required', 'integer', 'exists:bloque_horario,id_bloque_horario'],
            'observacion'       => ['nullable', 'string', 'max:200'],
        ]);

        Docente::where('estado', 'activo')->findOrFail($idDocente);

        $existente = DisponibilidadDocente::where('id_docente', $idDocente)
            ->where('id_bloque_horario', $request->id_bloque_horario)
            ->first();

        if ($existente && $existente->estado === 'activo') {
            // Toggle OFF → marcar disponible (eliminar registro)
            HistorialService::registrar(
                tabla:         'disponibilidad_docente',
                idRegistro:    $existente->id_disponibilidad_docente,
                tipoCambio:    'delete',
                valorAnterior: $existente->toArray(),
                motivo:        'Toggle: docente vuelve a estar disponible',
            );
            $existente->update(['estado' => 'inactivo']);

            return response()->json([
                'message'    => 'Bloque disponible nuevamente.',
                'disponible' => true,
            ]);
        }

        // Toggle ON → marcar no disponible
        if ($existente) {
            $existente->update([
                'estado'      => 'activo',
                'observacion' => $request->observacion,
                'fecha_actualizacion' => now(),
            ]);
            $disp = $existente;
        } else {
            $disp = DisponibilidadDocente::create([
                'id_docente'         => $idDocente,
                'id_bloque_horario'  => $request->id_bloque_horario,
                'observacion'        => $request->observacion,
                'estado'             => 'activo',
                'fecha_registro'     => now(),
                'fecha_actualizacion'=> now(),
            ]);
        }

        HistorialService::registrar(
            tabla:      'disponibilidad_docente',
            idRegistro: $disp->id_disponibilidad_docente,
            tipoCambio: 'insert',
            valorNuevo: $disp->toArray(),
            motivo:     'Toggle: docente marcó bloque como no disponible',
        );

        return response()->json([
            'message'    => 'Bloque marcado como no disponible.',
            'disponible' => false,
        ]);
    }
}
