<?php

namespace App\Services\Horario;

use Illuminate\Support\Collection;

/**
 * Resultado completo de una ejecución del GeneradorParcialService.
 *
 * FASE 2: estructura actualizada para multi-bloque.
 *
 *   asignacionesPropuestas — TODOS los bloques asignados (completos + parciales).
 *                            PersistenciaHorarioService persiste a partir de esta colección.
 *
 *   seccionesNoAsignables  — secciones con 0/N bloques asignados.
 *
 *   seccionesParciales     — secciones con 1..N-1 bloques asignados.
 *                            Sus bloques ya están en asignacionesPropuestas.
 *                            Esta colección es solo metadato de aviso.
 *
 * La distinción entre "completa" y "parcial" es semántica para el
 * coordinador. La persistencia no diferencia: todos los bloques en
 * asignacionesPropuestas se insertan en detalle_horario.
 *
 * Inmutable.
 */
final class GeneracionParcialResultado
{
    private function __construct(
        /** @var Collection<int, AsignacionPropuesta> Todos los bloques asignados */
        private readonly Collection $asignacionesPropuestas,

        /** @var Collection<int, SeccionNoAsignable> Secciones con 0 bloques */
        private readonly Collection $seccionesNoAsignables,

        /** @var Collection<int, SeccionNoAsignable> Secciones con 1..N-1 bloques */
        private readonly Collection $seccionesParciales,

        private readonly array $estadisticas,

        private readonly int $idCarreraJornada = 0,
    ) {}

    public static function crear(
        Collection $asignacionesPropuestas,
        Collection $seccionesNoAsignables,
        Collection $seccionesParciales,
        array      $estadisticas,
        int        $idCarreraJornada = 0,
    ): self {
        return new self(
            $asignacionesPropuestas,
            $seccionesNoAsignables,
            $seccionesParciales,
            $estadisticas,
            $idCarreraJornada,
        );
    }

    // ── Acceso ──────────────────────────────────────────────────

    /** @return Collection<int, AsignacionPropuesta> */
    public function asignacionesPropuestas(): Collection
    {
        return $this->asignacionesPropuestas;
    }

    /** @return Collection<int, SeccionNoAsignable> Secciones con 0 bloques */
    public function seccionesNoAsignables(): Collection
    {
        return $this->seccionesNoAsignables;
    }

    /** @return Collection<int, SeccionNoAsignable> Secciones con 1..N-1 bloques */
    public function seccionesParciales(): Collection
    {
        return $this->seccionesParciales;
    }

    public function estadisticas(): array
    {
        return $this->estadisticas;
    }

    public function idCarreraJornada(): int
    {
        return $this->idCarreraJornada;
    }

    // ── Contadores de conveniencia ───────────────────────────────

    /** Total de bloques asignados (incluye parciales) */
    public function totalAsignadas(): int
    {
        return $this->asignacionesPropuestas->count();
    }

    /** Secciones que no recibieron ningún bloque */
    public function totalNoAsignadas(): int
    {
        return $this->seccionesNoAsignables->count();
    }

    /** Secciones con asignación incompleta */
    public function totalParciales(): int
    {
        return $this->seccionesParciales->count();
    }

    public function tieneNoAsignadas(): bool
    {
        return $this->seccionesNoAsignables->isNotEmpty();
    }

    public function tieneParciales(): bool
    {
        return $this->seccionesParciales->isNotEmpty();
    }

    /** True solo si no hay ninguna sección sin bloque ni parcial */
    public function esCompleto(): bool
    {
        return $this->seccionesNoAsignables->isEmpty()
            && $this->seccionesParciales->isEmpty();
    }

    // ── Serialización ───────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'es_completo'              => $this->esCompleto(),
            'estadisticas'             => $this->estadisticas,
            'asignaciones'             => $this->asignacionesPropuestas
                ->map(fn($a) => $a->toArray())
                ->values()
                ->all(),
            'secciones_parciales'      => $this->seccionesParciales
                ->map(fn($s) => $s->toArray())
                ->values()
                ->all(),
            'secciones_no_asignables'  => $this->seccionesNoAsignables
                ->map(fn($s) => $s->toArray())
                ->values()
                ->all(),
        ];
    }
}
