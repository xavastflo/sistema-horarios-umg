<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Docente\StoreDocenteRequest;
use App\Http\Requests\Docente\UpdateDocenteRequest;
use App\Models\Docente;
use App\Models\Usuario;
use App\Services\HistorialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocenteController extends Controller
{
    /**
     * GET /api/docentes
     * Lista docentes con filtros opcionales.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Docente::with(['usuario.rolesActivos'])
            // SQL oficial: estado es ENUM string
            ->when($request->estado, fn($q) => $q->where('docente.estado', $request->estado))
            ->when($request->prioridad, fn($q) => $q->where('prioridad', $request->prioridad))
            ->when($request->buscar, fn($q) => $q->whereHas('usuario', fn($q2) => $q2
                ->where('nombres', 'like', "%{$request->buscar}%")
                ->orWhere('apellidos', 'like', "%{$request->buscar}%")
                ->orWhere('nombre_usuario', 'like', "%{$request->buscar}%")
            ))
            ->orderBy('prioridad', 'asc');  // 1=alta aparece primero

        return response()->json($query->get()->map(fn($d) => $this->formatDocente($d)));
    }

    /**
     * POST /api/docentes
     * Crea un docente a partir de un usuario con rol docente.
     */
    public function store(StoreDocenteRequest $request): JsonResponse
    {
        $usuario = Usuario::findOrFail($request->id_usuario);

        if (! $usuario->tieneRol('docente')) {
            return response()->json([
                'message' => 'El usuario debe tener el rol de docente asignado antes de crear el perfil docente.',
            ], 422);
        }

        $docente = Docente::create([
            'id_usuario'          => $request->id_usuario,
            // SQL oficial: varchar(20) DEFAULT NULL
            'codigo_docente'      => $request->codigo_docente ?? null,
            // SQL oficial: int DEFAULT 3 — baja si no se especifica
            'prioridad'           => $request->prioridad ?? Docente::PRIORIDAD_DEFAULT,
            'estado'              => 'activo',   // ENUM string
            'fecha_creacion'      => now(),
            'fecha_actualizacion' => now(),
        ]);

        HistorialService::registrarCreacion($docente, 'docente');

        return response()->json($this->formatDocente($docente->load('usuario')), 201);
    }

    /**
     * GET /api/docentes/{docente}
     */
    public function show(int $id): JsonResponse
    {
        $docente = Docente::with('usuario.rolesActivos')->findOrFail($id);
        return response()->json($this->formatDocente($docente));
    }

    /**
     * PUT /api/docentes/{docente}
     * Permite actualizar código, prioridad y estado.
     */
    public function update(UpdateDocenteRequest $request, int $id): JsonResponse
    {
        $docente = Docente::findOrFail($id);
        $datos   = $request->only(['codigo_docente', 'prioridad', 'estado']);

        HistorialService::registrarActualizacion($docente, 'docente',
            $request->has('prioridad') ? 'Actualización de prioridad docente' : null
        );

        $docente->update($datos);

        return response()->json($this->formatDocente($docente->load('usuario')));
    }

    /**
     * DELETE /api/docentes/{docente}
     */
    public function destroy(int $id): JsonResponse
    {
        $docente = Docente::findOrFail($id);

        HistorialService::registrarEliminacion($docente, 'docente');
        // SQL oficial: ENUM — usar string 'inactivo'
        $docente->update(['estado' => 'inactivo']);

        return response()->json(['message' => 'Docente desactivado correctamente.']);
    }

    /**
     * PATCH /api/docentes/{docente}/prioridad
     * Endpoint dedicado para cambiar solo la prioridad.
     */
    public function actualizarPrioridad(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'prioridad' => ['required', 'integer', 'in:1,2,3'],
        ], [
            'prioridad.in' => 'La prioridad debe ser 1 (alta), 2 (media) o 3 (baja).',
        ]);

        $docente = Docente::findOrFail($id);

        HistorialService::registrar(
            tabla:         'docente',
            idRegistro:    $docente->id_docente,
            tipoCambio:    'update',
            valorAnterior: ['prioridad' => $docente->prioridad],
            valorNuevo:    ['prioridad' => $request->prioridad],
            motivo:        'Cambio de prioridad docente',
        );

        $docente->update(['prioridad' => $request->prioridad]);

        return response()->json([
            'message'   => 'Prioridad actualizada.',
            'prioridad' => $docente->prioridad,
            'etiqueta'  => $docente->etiquetaPrioridad(),
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────
    private function formatDocente(Docente $docente): array
    {
        return [
            'id_docente'         => $docente->id_docente,
            'codigo_docente'     => $docente->codigo_docente,
            'prioridad'          => $docente->prioridad,
            'etiqueta_prioridad' => $docente->etiquetaPrioridad(),
            // SQL oficial: ENUM string
            'estado'             => $docente->estado,
            'fecha_creacion'     => $docente->fecha_creacion,
            'usuario'            => $docente->usuario ? [
                'id_usuario'         => $docente->usuario->id_usuario,
                'nombre_completo'    => $docente->usuario->nombresCompletos(),
                'nombre_usuario'     => $docente->usuario->nombre_usuario,
                'correo_electronico' => $docente->usuario->correo_electronico,
                'telefono'           => $docente->usuario->telefono,
            ] : null,
        ];
    }
}
