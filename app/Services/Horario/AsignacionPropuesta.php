<?php

namespace App\Services\Horario;

use App\Models\BloqueHorario;

/**
 * Representa una asignación docente-sección-bloque que pasó todas las
 * validaciones y está lista para ser persistida en detalle_horario.
 *
 * Inmutable. Creada por GeneradorParcialService, consumida por
 * PersistenciaHorarioService (Paso 6).
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
        ];
    }
}
