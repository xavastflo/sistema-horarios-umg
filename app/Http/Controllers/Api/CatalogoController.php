<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dia;
use App\Models\EstadoHorario;
use App\Models\Jornada;
use App\Models\Rol;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogoController extends Controller
{
    /**
     * GET /api/catalogos/roles
     */
    public function roles(): JsonResponse
    {
        return response()->json(Rol::activos()->orderBy('nombre_rol')->get());
    }

    /**
     * GET /api/catalogos/jornadas
     */
    public function jornadas(): JsonResponse
    {
        return response()->json(Jornada::activos()->orderBy('nombre_jornada')->get());
    }

    /**
     * GET /api/catalogos/dias
     */
    public function dias(): JsonResponse
    {
        return response()->json(Dia::activos()->orderBy('orden_semana')->get());
    }

    /**
     * GET /api/catalogos/estados-horario
     */
    public function estadosHorario(): JsonResponse
    {
        return response()->json(EstadoHorario::activos()->get());
    }
}
