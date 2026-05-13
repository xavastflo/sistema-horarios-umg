<?php

namespace App\Services;

use App\Models\HistorialCambios;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class HistorialService
{
    /**
     * Registra un cambio en el historial.
     *
     * IMPORTANTE: id_usuario es NOT NULL en el SQL oficial.
     * Si no hay usuario autenticado (caso excepcional de sistema),
     * se debe pasar explícitamente el id del usuario de sistema.
     *
     * @param string     $tabla           Nombre de la tabla afectada
     * @param int        $idRegistro      PK del registro afectado
     * @param string     $tipoCambio      insert|update|delete|aprobacion|bloqueo|duplicacion|asignacion
     * @param array|null $valorAnterior   Estado anterior (para update/delete)
     * @param array|null $valorNuevo      Estado nuevo (para insert/update)
     * @param string|null $motivo         Motivo del cambio
     * @param int|null   $idUsuario       Si null, toma el usuario autenticado
     */
    public static function registrar(
        string  $tabla,
        int     $idRegistro,
        string  $tipoCambio,
        ?array  $valorAnterior = null,
        ?array  $valorNuevo    = null,
        ?string $motivo        = null,
        ?int    $idUsuario     = null,
    ): HistorialCambios {
        $idUsuario = $idUsuario ?? Auth::id();

        // SQL oficial: id_usuario NOT NULL — lanzar excepción si no hay usuario
        if (! $idUsuario) {
            throw new \RuntimeException(
                'HistorialService::registrar requiere id_usuario. ' .
                'No hay usuario autenticado y no se proporcionó id_usuario explícito.'
            );
        }

        $registro = new HistorialCambios();
        $registro->id_usuario           = $idUsuario;
        $registro->tabla_afectada       = $tabla;
        $registro->id_registro_afectado = $idRegistro;
        $registro->tipo_cambio          = $tipoCambio;
        $registro->motivo_cambio        = $motivo;
        $registro->fecha_cambio         = now();

        // Usar mutators del modelo para serializar arrays a JSON string (columna TEXT)
        $registro->setValorAnteriorAttribute($valorAnterior);
        $registro->setValorNuevoAttribute($valorNuevo);

        $registro->save();

        return $registro;
    }

    /**
     * Registra la creación de un modelo (insert).
     */
    public static function registrarCreacion(Model $model, string $tabla): void
    {
        self::registrar(
            tabla:      $tabla,
            idRegistro: $model->getKey(),
            tipoCambio: 'insert',
            valorNuevo: $model->toArray(),
        );
    }

    /**
     * Registra actualización de un modelo.
     * IMPORTANTE: llamar ANTES de save() para capturar getOriginal().
     */
    public static function registrarActualizacion(
        Model   $model,
        string  $tabla,
        ?string $motivo = null,
    ): void {
        self::registrar(
            tabla:         $tabla,
            idRegistro:    $model->getKey(),
            tipoCambio:    'update',
            valorAnterior: $model->getOriginal(),
            valorNuevo:    $model->getDirty(),
            motivo:        $motivo,
        );
    }

    /**
     * Registra eliminación lógica o física.
     */
    public static function registrarEliminacion(Model $model, string $tabla): void
    {
        self::registrar(
            tabla:         $tabla,
            idRegistro:    $model->getKey(),
            tipoCambio:    'delete',
            valorAnterior: $model->toArray(),
        );
    }
}
