<?php

namespace App\Services\Horario;

/**
 * Resultado inmutable de una validación de conflictos de horario.
 *
 * Cada método de validación en ConflictValidationService devuelve
 * una instancia de esta clase. El algoritmo de generación la usa para
 * decidir si un bloque es asignable; el frontend la usa para mostrar
 * el motivo exacto del rechazo al coordinador.
 */
final class ValidacionResultado
{
    /** @var ConflictoItem[] */
    private array $conflictos;

    private function __construct(array $conflictos)
    {
        $this->conflictos = $conflictos;
    }

    // ── Constructores de fábrica ────────────────────────────────

    /** Sin ningún conflicto — el bloque es asignable. */
    public static function sinConflictos(): self
    {
        return new self([]);
    }

    /**
     * Con uno o más conflictos — el bloque NO es asignable.
     *
     * @param ConflictoItem[] $conflictos
     */
    public static function conConflictos(array $conflictos): self
    {
        return new self($conflictos);
    }

    /** Combina dos resultados en uno (útil para ejecutar varias validaciones). */
    public function merge(self $otro): self
    {
        return new self(array_merge($this->conflictos, $otro->conflictos));
    }

    // ── Estado ──────────────────────────────────────────────────

    public function esValido(): bool
    {
        return empty($this->conflictos);
    }

    public function tieneConflictos(): bool
    {
        return ! empty($this->conflictos);
    }

    /** @return ConflictoItem[] */
    public function conflictos(): array
    {
        return $this->conflictos;
    }

    public function primerConflicto(): ?ConflictoItem
    {
        return $this->conflictos[0] ?? null;
    }

    // ── Serialización para responses API ───────────────────────

    public function toArray(): array
    {
        return [
            'es_valido'  => $this->esValido(),
            'conflictos' => array_map(fn($c) => $c->toArray(), $this->conflictos),
        ];
    }
}
