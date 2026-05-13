<?php

namespace App\Services\Horario;

use App\Models\DetalleHorario;

/**
 * Resultado de una operación de edición manual de horario.
 *
 * Dos estados posibles:
 *   - exitoso:   detalle actualizado disponible, mensaje informativo
 *   - rechazado: tipo de rechazo, mensaje y ValidacionResultado opcional
 */
final class EdicionManualResultado
{
    private function __construct(
        public readonly bool              $exitoso,
        public readonly string            $mensaje,
        public readonly ?string           $tipo,
        public readonly ?DetalleHorario   $detalle,
        public readonly ?ValidacionResultado $validacion,
    ) {}

    public static function exitoso(
        ?DetalleHorario $detalle,
        string          $mensaje,
    ): self {
        return new self(
            exitoso:    true,
            mensaje:    $mensaje,
            tipo:       null,
            detalle:    $detalle,
            validacion: null,
        );
    }

    public static function rechazado(
        string               $tipo,
        string               $mensaje,
        ?ValidacionResultado $validacion = null,
    ): self {
        return new self(
            exitoso:    false,
            mensaje:    $mensaje,
            tipo:       $tipo,
            detalle:    null,
            validacion: $validacion,
        );
    }

    public function toArray(): array
    {
        $data = [
            'exitoso' => $this->exitoso,
            'mensaje' => $this->mensaje,
        ];

        if ($this->exitoso && $this->detalle) {
            $data['detalle'] = [
                'id_detalle_horario'          => $this->detalle->id_detalle_horario,
                'id_horario'                  => $this->detalle->id_horario,
                'id_bloque_horario'           => $this->detalle->id_bloque_horario,
                'id_dia'                      => $this->detalle->id_dia,
                'id_asignacion_docente_curso' => $this->detalle->id_asignacion_docente_curso,
                'estado'                      => $this->detalle->estado,
            ];
        }

        if (! $this->exitoso) {
            $data['tipo'] = $this->tipo;
            if ($this->validacion) {
                $data['conflictos'] = $this->validacion->toArray();
            }
        }

        return $data;
    }
}
