<?php

namespace App\Services\Horario;

use App\Models\Horario;

/**
 * Resultado de una operación de persistencia de horario.
 *
 * Informa si la operación fue exitosa, cuántos detalles se insertaron,
 * y en caso de fallo, qué propuesta generó el conflicto y por qué.
 */
final class PersistenciaResultado
{
    private function __construct(
        public readonly bool    $exitoso,
        public readonly int     $detallesInsertados,
        public readonly string  $mensaje,
        /** Estado del horario después de la operación */
        public readonly ?string $estadoHorario,
        /** Si falló, la propuesta que causó el conflicto */
        public readonly ?AsignacionPropuesta $propuestaConflictiva,
        /** Si falló, el resultado de validación que lo explica */
        public readonly ?ValidacionResultado $conflicto,
    ) {}

    public static function exitoso(
        int     $detallesInsertados,
        string  $estadoHorario,
    ): self {
        return new self(
            exitoso:              true,
            detallesInsertados:   $detallesInsertados,
            mensaje:              "Se persistieron {$detallesInsertados} detalles de horario correctamente.",
            estadoHorario:        $estadoHorario,
            propuestaConflictiva: null,
            conflicto:            null,
        );
    }

    public static function fallido(
        string               $mensaje,
        AsignacionPropuesta  $propuesta,
        ValidacionResultado  $conflicto,
    ): self {
        return new self(
            exitoso:              false,
            detallesInsertados:   0,
            mensaje:              $mensaje,
            estadoHorario:        null,
            propuestaConflictiva: $propuesta,
            conflicto:            $conflicto,
        );
    }

    public static function contextoInvalido(string $mensaje): self
    {
        return new self(
            exitoso:              false,
            detallesInsertados:   0,
            mensaje:              $mensaje,
            estadoHorario:        null,
            propuestaConflictiva: null,
            conflicto:            null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'exitoso'             => $this->exitoso,
            'detalles_insertados' => $this->detallesInsertados,
            'mensaje'             => $this->mensaje,
            'estado_horario'      => $this->estadoHorario,
        ];

        if (! $this->exitoso && $this->propuestaConflictiva) {
            $data['propuesta_conflictiva'] = $this->propuestaConflictiva->toArray();
            $data['conflicto']             = $this->conflicto?->toArray();
        }

        return $data;
    }
}
