<?php

namespace App\Services\Horario;

use Illuminate\Support\Collection;

/**
 * Resultado completo de una ejecución del GeneradorParcialService.
 *
 * Contiene:
 *   - asignacionesPropuestas: secciones asignadas, listas para persistir
 *   - seccionesNoAsignables:  secciones que no pudieron asignarse con motivo
 *   - estadisticas:           resumen numérico para informes y logs
 *
 * Inmutable. No contiene lógica de persistencia — eso es responsabilidad
 * de PersistenciaHorarioService (Paso 6).
 */
final class GeneracionParcialResultado
{
    private function __construct(
        /** @var Collection<int, AsignacionPropuesta> */
        private readonly Collection $asignacionesPropuestas,

        /** @var Collection<int, SeccionNoAsignable> */
        private readonly Collection $seccionesNoAsignables,

        private readonly array $estadisticas,
    ) {}

    public static function crear(
        Collection $asignacionesPropuestas,
        Collection $seccionesNoAsignables,
        array      $estadisticas,
    ): self {
        return new self($asignacionesPropuestas, $seccionesNoAsignables, $estadisticas);
    }

    // ── Acceso ──────────────────────────────────────────────────

    /** @return Collection<int, AsignacionPropuesta> */
    public function asignacionesPropuestas(): Collection
    {
        return $this->asignacionesPropuestas;
    }

    /** @return Collection<int, SeccionNoAsignable> */
    public function seccionesNoAsignables(): Collection
    {
        return $this->seccionesNoAsignables;
    }

    public function estadisticas(): array
    {
        return $this->estadisticas;
    }

    public function totalAsignadas(): int
    {
        return $this->asignacionesPropuestas->count();
    }

    public function totalNoAsignadas(): int
    {
        return $this->seccionesNoAsignables->count();
    }

    public function tieneNoAsignadas(): bool
    {
        return $this->seccionesNoAsignables->isNotEmpty();
    }

    public function esCompleto(): bool
    {
        return $this->seccionesNoAsignables->isEmpty();
    }

    // ── Serialización ───────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'es_completo'           => $this->esCompleto(),
            'estadisticas'          => $this->estadisticas,
            'asignaciones'          => $this->asignacionesPropuestas
                ->map(fn($a) => $a->toArray())
                ->values()
                ->all(),
            'secciones_no_asignables' => $this->seccionesNoAsignables
                ->map(fn($s) => $s->toArray())
                ->values()
                ->all(),
        ];
    }
}
