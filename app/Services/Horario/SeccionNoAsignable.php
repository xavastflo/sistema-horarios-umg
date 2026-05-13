<?php

namespace App\Services\Horario;

/**
 * Describe una sección que no pudo asignarse durante la generación parcial.
 *
 * Contiene el motivo global (p.ej. "sin_candidatos") y el detalle
 * de por qué cada bloque fue descartado, para que el coordinador
 * pueda tomar decisiones informadas al revisar el horario generado.
 */
final class SeccionNoAsignable
{
    // Razones globales por las que una sección no puede asignarse
    const SIN_ASIGNACION_DOCENTE = 'sin_asignacion_docente';
    const SIN_BLOQUES_DEFINIDOS  = 'sin_bloques_definidos';
    const SIN_CANDIDATOS         = 'sin_candidatos';
    const FECHA_LIMITE_VENCIDA   = 'fecha_limite_vencida';
    const HORARIO_NO_EDITABLE    = 'horario_no_editable';

    public function __construct(
        public readonly int    $idSeccion,
        public readonly string $nombreCurso,
        public readonly string $numeroSeccion,
        public readonly int    $cicloSemestre,
        /** Razón global del fallo */
        public readonly string $razon,
        /** Mensaje legible para el coordinador */
        public readonly string $mensaje,
        /**
         * Detalle de cada bloque descartado, si aplica.
         * Proviene de BloqueCandidatoResultado::bloquesDescartados().
         * Array de BloqueDescartado::toArray().
         */
        public readonly array  $bloquesDescartados = [],
    ) {}

    public function toArray(): array
    {
        return [
            'id_seccion'          => $this->idSeccion,
            'curso'               => $this->nombreCurso,
            'numero_seccion'      => $this->numeroSeccion,
            'ciclo_semestre'      => $this->cicloSemestre,
            'razon'               => $this->razon,
            'mensaje'             => $this->mensaje,
            'bloques_descartados' => $this->bloquesDescartados,
        ];
    }
}
