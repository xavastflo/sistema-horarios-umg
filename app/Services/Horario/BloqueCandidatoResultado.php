<?php

namespace App\Services\Horario;

use App\Models\BloqueHorario;
use Illuminate\Support\Collection;

/**
 * Resultado de la selección de bloques candidatos para una asignación.
 *
 * Contiene:
 *   - Bloques válidos:    Collection<BloqueHorario> listos para asignar
 *   - Bloques descartados: Collection<BloqueDescartado> con motivo de rechazo
 *
 * Inmutable después de construcción.
 */
final class BloqueCandidatoResultado
{
    private function __construct(
        /** @var Collection<int, BloqueHorario> */
        private readonly Collection $bloquesValidos,

        /** @var Collection<int, BloqueDescartado> */
        private readonly Collection $bloquesDescartados,

        /** Contexto de la consulta para trazabilidad */
        private readonly array $contexto,
    ) {}

    public static function crear(
        Collection $bloquesValidos,
        Collection $bloquesDescartados,
        array      $contexto = [],
    ): self {
        return new self($bloquesValidos, $bloquesDescartados, $contexto);
    }

    // ── Acceso ──────────────────────────────────────────────────

    /** @return Collection<int, BloqueHorario> */
    public function bloquesValidos(): Collection
    {
        return $this->bloquesValidos;
    }

    /** @return Collection<int, BloqueDescartado> */
    public function bloquesDescartados(): Collection
    {
        return $this->bloquesDescartados;
    }

    public function totalValidos(): int
    {
        return $this->bloquesValidos->count();
    }

    public function totalDescartados(): int
    {
        return $this->bloquesDescartados->count();
    }

    public function tieneBloquesValidos(): bool
    {
        return $this->bloquesValidos->isNotEmpty();
    }

    public function contexto(): array
    {
        return $this->contexto;
    }

    // ── Serialización para responses API y logs ─────────────────

    public function toArray(): array
    {
        return [
            'total_validos'     => $this->totalValidos(),
            'total_descartados' => $this->totalDescartados(),
            'contexto'          => $this->contexto,
            'bloques_validos'   => $this->bloquesValidos->map(fn($b) => [
                'id_bloque_horario' => $b->id_bloque_horario,
                'id_dia'            => $b->id_dia,
                'hora_inicio'       => $b->hora_inicio,
                'hora_fin'          => $b->hora_fin,
                'duracion_minutos'  => $b->duracion_minutos,
            ])->values()->all(),
            'bloques_descartados' => $this->bloquesDescartados->map(
                fn($d) => $d->toArray()
            )->values()->all(),
        ];
    }
}
