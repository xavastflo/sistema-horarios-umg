<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Carrera\StoreCarreraRequest;
use App\Http\Requests\Carrera\UpdateCarreraRequest;
use App\Models\Carrera;
use App\Models\Usuario;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarreraController extends Controller
{
    /**
     * GET /api/carreras
     */
    public function index(Request $request): JsonResponse
    {
        $query = Carrera::with(['facultad', 'coordinador', 'jornadasActivas'])
            ->when($request->estado !== null, fn($q) => $q->where('carrera.estado', $request->estado))
            ->when($request->id_facultad, fn($q) => $q->where('id_facultad', $request->id_facultad))
            ->when($request->buscar, fn($q) => $q->where(function ($q2) use ($request) {
                $q2->where('nombre_carrera', 'like', "%{$request->buscar}%")
                   ->orWhere('codigo_carrera', 'like', "%{$request->buscar}%");
            }))
            ->orderBy('nombre_carrera');

        return response()->json($query->get());
    }

    /**
     * POST /api/carreras
     */
    public function store(StoreCarreraRequest $request): JsonResponse
    {
        $carrera = Carrera::create([
            'id_facultad'    => $request->id_facultad,
            'nombre_carrera' => $request->nombre_carrera,
            'codigo_carrera' => strtoupper($request->codigo_carrera),
            'estado'         => 'activo',   // ENUM string
            'fecha_creacion' => now(),
        ]);

        HistorialService::registrarCreacion($carrera, 'carrera');

        return response()->json($carrera->load('facultad'), 201);
    }

    /**
     * GET /api/carreras/{carrera}
     */
    public function show(int $id): JsonResponse
    {
        $carrera = Carrera::with(['facultad', 'coordinador', 'jornadasActivas'])->findOrFail($id);
        return response()->json($carrera);
    }

    /**
     * PUT /api/carreras/{carrera}
     */
    public function update(UpdateCarreraRequest $request, int $id): JsonResponse
    {
        $carrera = Carrera::findOrFail($id);
        $datos   = $request->only(['id_facultad', 'nombre_carrera', 'estado']);

        if ($request->has('codigo_carrera')) {
            $datos['codigo_carrera'] = strtoupper($request->codigo_carrera);
        }

        HistorialService::registrarActualizacion($carrera, 'carrera');
        $carrera->update($datos);

        return response()->json($carrera->load('facultad', 'coordinador'));
    }

    /**
     * DELETE /api/carreras/{carrera}
     */
    public function destroy(int $id): JsonResponse
    {
        $carrera = Carrera::findOrFail($id);

        HistorialService::registrarEliminacion($carrera, 'carrera');
        // SQL oficial: ENUM — usar string 'inactivo'
        $carrera->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Carrera desactivada correctamente.']);
    }

    /**
     * POST /api/carreras/{carrera}/coordinador
     */
    public function asignarCoordinador(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'id_usuario' => ['required', 'integer', 'exists:usuario,id_usuario'],
        ]);

        $carrera = Carrera::findOrFail($id);
        $usuario = Usuario::findOrFail($request->id_usuario);

        // Validar que el usuario esté activo
        if ($usuario->estado !== 'activo') {
            return response()->json([
                'message' => 'El usuario seleccionado no está activo en el sistema.',
            ], 422);
        }

        // Validar que el usuario tenga rol coordinador activo
        if (! $usuario->tieneRol('coordinador')) {
            return response()->json([
                'message' => 'El usuario seleccionado no tiene el rol de coordinador activo.',
            ], 422);
        }

        $anterior = [
            'id_usuario_coordinador'       => $carrera->id_usuario_coordinador,
            'fecha_asignacion_coordinador' => $carrera->fecha_asignacion_coordinador,
        ];

        $carrera->update([
            'id_usuario_coordinador'          => $usuario->id_usuario,
            'fecha_asignacion_coordinador'    => now(),
            'fecha_desasignacion_coordinador' => null,
        ]);

        HistorialService::registrar(
            tabla:         'carrera',
            idRegistro:    $carrera->id_carrera,
            tipoCambio:    'asignacion',
            valorAnterior: $anterior,
            valorNuevo:    ['id_usuario_coordinador' => $usuario->id_usuario, 'nombre' => $usuario->nombresCompletos()],
            motivo:        'Asignación de coordinador',
        );

        return response()->json([
            'message' => 'Coordinador asignado correctamente.',
            'carrera' => $carrera->load('coordinador'),
        ]);
    }

    /**
     * DELETE /api/carreras/{carrera}/coordinador
     */
    public function desasignarCoordinador(int $id): JsonResponse
    {
        $carrera = Carrera::findOrFail($id);

        if (! $carrera->id_usuario_coordinador) {
            return response()->json(['message' => 'La carrera no tiene coordinador asignado.'], 422);
        }

        $anterior = ['id_usuario_coordinador' => $carrera->id_usuario_coordinador];

        $carrera->update([
            'id_usuario_coordinador'          => null,
            'fecha_desasignacion_coordinador' => now(),
        ]);

        HistorialService::registrar(
            tabla:         'carrera',
            idRegistro:    $carrera->id_carrera,
            tipoCambio:    'update',
            valorAnterior: $anterior,
            valorNuevo:    ['id_usuario_coordinador' => null],
            motivo:        'Desasignación de coordinador',
        );

        return response()->json(['message' => 'Coordinador desasignado correctamente.']);
    }

    /**
     * POST /api/carreras/{carrera}/jornadas
     */
    public function asignarJornadas(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'jornadas'   => ['required', 'array', 'min:1'],
            'jornadas.*' => ['integer', 'exists:jornada,id_jornada'],
        ]);

        $carrera = Carrera::findOrFail($id);

        $asignadas = [];
        $ignoradas = [];

        foreach ($request->jornadas as $idJornada) {
            $existe = \DB::table('carrera_jornada')
                ->where('id_carrera', $id)
                ->where('id_jornada', $idJornada)
                ->first();

            if ($existe) {
                if ($existe->estado === 'inactivo') {
                    \DB::table('carrera_jornada')
                        ->where('id_carrera', $id)
                        ->where('id_jornada', $idJornada)
                        ->update(['estado' => 'activo']);  // ENUM string
                    $asignadas[] = $idJornada;
                } else {
                    $ignoradas[] = $idJornada;
                }
            } else {
                \DB::table('carrera_jornada')->insert([
                    'id_carrera'     => $id,
                    'id_jornada'     => $idJornada,
                    'estado'         => 'activo',   // ENUM string
                    'fecha_creacion' => now(),
                ]);
                $asignadas[] = $idJornada;
            }
        }

        return response()->json([
            'message'   => 'Jornadas procesadas.',
            'asignadas' => $asignadas,
            'ignoradas' => $ignoradas,
            'carrera'   => $carrera->load('jornadasActivas'),
        ]);
    }
}
