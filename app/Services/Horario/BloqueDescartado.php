<?php

namespace App\Services\Horario;

use App\Models\BloqueHorario;

/**
 * Describe un bloque horario que fue descartado durante la selección
 * de candidatos, junto con el motivo de su rechazo.
 *
 * Usado en BloqueCandidatoResultado::bloquesDescartados().
 */
final class BloqueDescartado
{
    public function __construct(
        public readonly BloqueHorario    $bloque,
        public readonly ValidacionResultado $razon,
    ) {}

    public function toArray(): array
    {
        return [
            'id_bloque_horario' => $this->bloque->id_bloque_horario,
            'id_dia'            => $this->bloque->id_dia,
            'hora_inicio'       => $this->bloque->hora_inicio,
            'hora_fin'          => $this->bloque->hora_fin,
            'motivos'           => array_map(
                fn($c) => $c->toArray(),
                $this->razon->conflictos()
            ),
        ];
    }
}
