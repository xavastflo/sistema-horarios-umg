<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CentroEducativo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CentroEducativoController
 *
 * CRUD de sedes/centros educativos.
 * Todas las rutas requieren rol: administrador.
 *
 * GET    /centros-educativos              → listar (filtro: estado)
 * POST   /centros-educativos              → crear
 * GET    /centros-educativos/{id}         → ver con facultades
 * PUT    /centros-educativos/{id}         → actualizar
 * DELETE /centros-educativos/{id}         → desactivar (sin FK activas) o eliminar
 */
class CentroEducativoController extends Controller
{
    /**
     * GET /centros-educativos
     * Filtro opcional: ?estado=activo|inactivo
     */
    public function index(Request $request): JsonResponse
    {
        $query = CentroEducativo::withCount(['facultades', 'facultadesActivas']);

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        return response()->json($query->orderBy('nombre')->get());
    }

    /**
     * POST /centros-educativos
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nombre'       => ['required', 'string', 'max:150'],
            'codigo_sede'  => ['nullable', 'string', 'max:20', 'unique:centro_educativo,codigo_sede'],
            'direccion'    => ['nullable', 'string', 'max:255'],
            'estado'       => ['sometimes', 'in:activo,inactivo'],
        ]);

        $centro = CentroEducativo::create([
            'nombre'      => $request->nombre,
            'codigo_sede' => $request->codigo_sede
                ? strtoupper(trim($request->codigo_sede))
                : null,
            'direccion'   => $request->direccion,
            'estado'      => $request->estado ?? 'activo',
        ]);

        return response()->json($centro, 201);
    }

    /**
     * GET /centros-educativos/{id}
     * Incluye facultades activas.
     */
    public function show(int $id): JsonResponse
    {
        $centro = CentroEducativo::with('facultadesActivas')
            ->withCount(['facultades', 'facultadesActivas'])
            ->findOrFail($id);

        return response()->json($centro);
    }

    /**
     * PUT /centros-educativos/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $centro = CentroEducativo::findOrFail($id);

        $request->validate([
            'nombre'      => ['sometimes', 'required', 'string', 'max:150'],
            'codigo_sede' => [
                'nullable', 'string', 'max:20',
                "unique:centro_educativo,codigo_sede,{$centro->id_centro_educativo},id_centro_educativo",
            ],
            'direccion'   => ['nullable', 'string', 'max:255'],
            'estado'      => ['sometimes', 'in:activo,inactivo'],
        ]);

        $datos = $request->only(['nombre', 'direccion', 'estado']);

        if ($request->has('codigo_sede')) {
            $datos['codigo_sede'] = $request->codigo_sede
                ? strtoupper(trim($request->codigo_sede))
                : null;
        }

        $centro->update($datos);

        return response()->json($centro);
    }

    /**
     * DELETE /centros-educativos/{id}
     * Desactiva el centro si tiene facultades; elimina físicamente si está vacío.
     */
    public function destroy(int $id): JsonResponse
    {
        $centro = CentroEducativo::withCount('facultadesActivas')->findOrFail($id);

        if ($centro->facultades_activas_count > 0) {
            return response()->json([
                'message' => "No se puede eliminar: el centro tiene {$centro->facultades_activas_count} facultad(es) activa(s). Desactívalas primero.",
            ], 422);
        }

        // Sin facultades activas → desactivación lógica
        $centro->update(['estado' => 'inactivo']);

        return response()->json([
            'message' => 'Centro educativo desactivado correctamente.',
        ]);
    }
}
