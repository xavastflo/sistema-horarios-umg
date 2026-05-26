<?php

namespace App\Services\Horario;

/**
 * Representa una asignación docente-sección-bloque que pasó todas las
 * validaciones y está lista para ser persistida en detalle_horario.
 *
 * Inmutable. Creada por GeneradorParcialService, consumida por
 * PersistenciaHorarioService.
 *
 * FASE 2: añadidos numBloque y bloquesRequeridos para soportar
 * multi-bloque por sección. Una sección con bloques_semanales = 2
 * genera dos AsignacionPropuesta: numBloque=1 y numBloque=2.
 * Ambas van a asignacionesPropuestas y ambas se persisten.
 */
final class AsignacionPropuesta
{
    public function __construct(
        public readonly int          $idAsignacionDocenteCurso,
        public readonly int          $idSeccion,
        public readonly int          $idDocente,
        public readonly int          $idBloque,
        public readonly int          $idDia,
        public readonly string       $horaInicio,
        public readonly string       $horaFin,
        public readonly string       $nombreDia,
        public readonly string       $nombreCurso,
        public readonly string       $nombreDocente,
        public readonly int          $cicloSemestre,
        public readonly int          $prioridadDocente,
        /** Posición de este bloque dentro de la sección (1-based) */
        public readonly int          $numBloque        = 1,
        /** Total de bloques que requiere la sección según pensum_curso */
        public readonly int          $bloquesRequeridos = 1,
    ) {}

    public function toArray(): array
    {
        return [
            'id_asignacion_docente_curso' => $this->idAsignacionDocenteCurso,
            'id_seccion'                  => $this->idSeccion,
            'id_docente'                  => $this->idDocente,
            'id_bloque_horario'           => $this->idBloque,
            'id_dia'                      => $this->idDia,
            'hora_inicio'                 => $this->horaInicio,
            'hora_fin'                    => $this->horaFin,
            'dia'                         => $this->nombreDia,
            'curso'                       => $this->nombreCurso,
            'docente'                     => $this->nombreDocente,
            'ciclo_semestre'              => $this->cicloSemestre,
            'prioridad_docente'           => $this->prioridadDocente,
            'num_bloque'                  => $this->numBloque,
            'bloques_requeridos'          => $this->bloquesRequeridos,
        ];
    }
}
