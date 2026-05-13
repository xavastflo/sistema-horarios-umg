<?php

namespace App\Services\Horario;

use App\Models\Horario;

/**
 * Resultado de una transición de estado de horario.
 *
 * Dos estados posibles:
 *   - exitoso:   transición realizada, horario actualizado disponible
 *   - rechazado: tipo de rechazo y mensaje explicativo
 */
final class HorarioStateResultado
{
    private function __construct(
        public readonly bool     $exitoso,
        public readonly string   $mensaje,
        public readonly ?string  $tipo,
        public readonly ?string  $estadoAnterior,
        public readonly ?string  $estadoNuevo,
        public readonly ?Horario $horario,
    ) {}

    public static function exitoso(
        Horario $horario,
        string  $estadoAnterior,
        string  $estadoNuevo,
    ): self {
        return new self(
            exitoso:        true,
            mensaje:        "Horario actualizado de '{$estadoAnterior}' a '{$estadoNuevo}' correctamente.",
            tipo:           null,
            estadoAnterior: $estadoAnterior,
            estadoNuevo:    $estadoNuevo,
            horario:        $horario,
        );
    }

    public static function rechazado(
        string $tipo,
        string $mensaje,
    ): self {
        return new self(
            exitoso:        false,
            mensaje:        $mensaje,
            tipo:           $tipo,
            estadoAnterior: null,
            estadoNuevo:    null,
            horario:        null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'exitoso' => $this->exitoso,
            'mensaje' => $this->mensaje,
        ];

        if ($this->exitoso) {
            $data['estado_anterior'] = $this->estadoAnterior;
            $data['estado_nuevo']    = $this->estadoNuevo;
            $data['horario']         = [
                'id_horario'         => $this->horario->id_horario,
                'id_estado_horario'  => $this->horario->id_estado_horario,
                'fecha_aprobacion'   => $this->horario->fecha_aprobacion?->toDateTimeString(),
                'fecha_bloqueo'      => $this->horario->fecha_bloqueo?->toDateTimeString(),
                'fecha_actualizacion'=> $this->horario->fecha_actualizacion?->toDateTimeString(),
            ];
        } else {
            $data['tipo'] = $this->tipo;
        }

        return $data;
    }
}
