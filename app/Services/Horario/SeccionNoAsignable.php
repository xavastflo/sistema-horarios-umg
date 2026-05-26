<?php

namespace App\Services\Horario;

/**
 * Describe una sección que no pudo asignarse completamente durante la generación.
 *
 * Cubre dos casos:
 *   1. 0 bloques asignados (SIN_CANDIDATOS, SIN_BLOQUES_DEFINIDOS, etc.)
 *   2. 1..N-1 bloques asignados de N requeridos (ASIGNACION_PARCIAL)
 *
 * En el caso parcial, los bloques asignados SÍ están en asignacionesPropuestas
 * del resultado y SÍ se persisten. Esta entrada solo actúa como metadato
 * de aviso al coordinador.
 *
 * FASE 2: añadidos bloquesAsignados, bloquesRequeridos y ASIGNACION_PARCIAL.
 */
final class SeccionNoAsignable
{
    const SIN_ASIGNACION_DOCENTE = 'sin_asignacion_docente';
    const SIN_BLOQUES_DEFINIDOS  = 'sin_bloques_definidos';
    const SIN_CANDIDATOS         = 'sin_candidatos';
    const ASIGNACION_PARCIAL     = 'asignacion_parcial';   // nuevo en Fase 2
    const FECHA_LIMITE_VENCIDA   = 'fecha_limite_vencida';
    const HORARIO_NO_EDITABLE    = 'horario_no_editable';

    public function __construct(
        public readonly int    $idSeccion,
        public readonly string $nombreCurso,
        public readonly string $numeroSeccion,
        public readonly int    $cicloSemestre,
        public readonly string $razon,
        public readonly string $mensaje,
        /**
         * Cuántos bloques se lograron asignar.
         * 0 = sin asignación, 1..N-1 = parcial.
         */
        public readonly int    $bloquesAsignados  = 0,
        /**
         * Cuántos bloques requería la sección según pensum_curso.bloques_semanales.
         */
        public readonly int    $bloquesRequeridos = 1,
        /**
         * Detalle de bloques descartados (solo para razón SIN_CANDIDATOS).
         */
        public readonly array  $bloquesDescartados = [],
    ) {}

    public function esParcial(): bool
    {
        return $this->bloquesAsignados > 0 && $this->bloquesAsignados < $this->bloquesRequeridos;
    }

    public function toArray(): array
    {
        return [
            'id_seccion'          => $this->idSeccion,
            'curso'               => $this->nombreCurso,
            'numero_seccion'      => $this->numeroSeccion,
            'ciclo_semestre'      => $this->cicloSemestre,
            'razon'               => $this->razon,
            'mensaje'             => $this->mensaje,
            'bloques_asignados'   => $this->bloquesAsignados,
            'bloques_requeridos'  => $this->bloquesRequeridos,
            'bloques_descartados' => $this->bloquesDescartados,
        ];
    }
}
