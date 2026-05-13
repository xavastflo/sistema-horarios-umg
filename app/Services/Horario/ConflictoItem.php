<?php

namespace App\Services\Horario;

/**
 * Describe un conflicto de asignación de horario.
 *
 * Tipos de conflicto definidos como constantes para que el algoritmo
 * y el frontend puedan reaccionar a cada tipo de forma diferente.
 */
final class ConflictoItem
{
    // ── Tipos de conflicto ───────────────────────────────────────

    /** El período académico superó su fecha límite de edición. */
    const FECHA_LIMITE_VENCIDA = 'fecha_limite_vencida';

    /** El horario está en estado bloqueado o aprobado — no editable. */
    const HORARIO_NO_EDITABLE = 'horario_no_editable';

    /** El docente registró ese bloque como no disponible. */
    const DOCENTE_NO_DISPONIBLE = 'docente_no_disponible';

    /**
     * El docente ya tiene una clase asignada en ese bloque.
     * Aplica incluso si la otra clase es de una carrera diferente.
     */
    const DOCENTE_OCUPADO = 'docente_ocupado';

    /**
     * Ya existe una clase del mismo ciclo_semestre en ese bloque
     * dentro del mismo horario. Un ciclo no puede impartir dos
     * clases simultáneamente.
     */
    const CICLO_TRASLAPE = 'ciclo_traslape';

    /**
     * El bloque ya está ocupado dentro de este horario por otra sección.
     * Basado en la restricción UNIQUE(id_horario, id_bloque_horario)
     * de detalle_horario.
     */
    const BLOQUE_OCUPADO_EN_HORARIO = 'bloque_ocupado_en_horario';

    // ────────────────────────────────────────────────────────────

    private function __construct(
        public readonly string $tipo,
        public readonly string $mensaje,
        public readonly array  $contexto = [],
    ) {}

    // ── Fábricas con mensajes canónicos ────────────────────────

    public static function fechaLimiteVencida(\DateTimeInterface $limite): self
    {
        return new self(
            tipo:     self::FECHA_LIMITE_VENCIDA,
            mensaje:  'El período académico superó la fecha límite de edición de horarios.',
            contexto: ['fecha_limite' => $limite->format('Y-m-d H:i:s')],
        );
    }

    public static function horarioNoEditable(string $estadoActual): self
    {
        return new self(
            tipo:     self::HORARIO_NO_EDITABLE,
            mensaje:  "El horario está en estado '{$estadoActual}' y no puede modificarse.",
            contexto: ['estado_horario' => $estadoActual],
        );
    }

    public static function docenteNoDisponible(
        int    $idDocente,
        int    $idBloque,
        string $horaInicio,
        string $horaFin,
        string $nombreDia,
    ): self {
        return new self(
            tipo:    self::DOCENTE_NO_DISPONIBLE,
            mensaje: "El docente marcó el bloque {$nombreDia} {$horaInicio}-{$horaFin} como no disponible.",
            contexto: [
                'id_docente'        => $idDocente,
                'id_bloque_horario' => $idBloque,
                'dia'               => $nombreDia,
                'hora_inicio'       => $horaInicio,
                'hora_fin'          => $horaFin,
            ],
        );
    }

    public static function docenteOcupado(
        int    $idDocente,
        int    $idBloque,
        string $horaInicio,
        string $horaFin,
        string $nombreDia,
        int    $idHorarioConflicto,
        string $nombreCarreraConflicto,
    ): self {
        return new self(
            tipo:    self::DOCENTE_OCUPADO,
            mensaje: "El docente ya tiene clase el {$nombreDia} {$horaInicio}-{$horaFin} "
                   . "(en carrera: {$nombreCarreraConflicto}).",
            contexto: [
                'id_docente'               => $idDocente,
                'id_bloque_horario'        => $idBloque,
                'dia'                      => $nombreDia,
                'hora_inicio'              => $horaInicio,
                'hora_fin'                 => $horaFin,
                'id_horario_conflicto'     => $idHorarioConflicto,
                'carrera_conflicto'        => $nombreCarreraConflicto,
            ],
        );
    }

    public static function cicloTraslape(
        int $cicloSemestre,
        int $idBloque,
        string $horaInicio,
        string $horaFin,
        string $nombreDia,
        string $nombreCursoConflicto,
    ): self {
        return new self(
            tipo:    self::CICLO_TRASLAPE,
            mensaje: "Ya existe una clase del ciclo {$cicloSemestre} el {$nombreDia} "
                   . "{$horaInicio}-{$horaFin} (curso: {$nombreCursoConflicto}).",
            contexto: [
                'ciclo_semestre'      => $cicloSemestre,
                'id_bloque_horario'   => $idBloque,
                'dia'                 => $nombreDia,
                'hora_inicio'         => $horaInicio,
                'hora_fin'            => $horaFin,
                'curso_conflicto'     => $nombreCursoConflicto,
            ],
        );
    }

    public static function bloqueOcupadoEnHorario(
        int    $idBloque,
        string $horaInicio,
        string $horaFin,
        string $nombreDia,
        string $nombreSeccionConflicto,
    ): self {
        return new self(
            tipo:    self::BLOQUE_OCUPADO_EN_HORARIO,
            mensaje: "El bloque {$nombreDia} {$horaInicio}-{$horaFin} ya está asignado "
                   . "en este horario (sección: {$nombreSeccionConflicto}).",
            contexto: [
                'id_bloque_horario'    => $idBloque,
                'dia'                  => $nombreDia,
                'hora_inicio'          => $horaInicio,
                'hora_fin'             => $horaFin,
                'seccion_conflicto'    => $nombreSeccionConflicto,
            ],
        );
    }

    // ── Serialización ───────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'tipo'     => $this->tipo,
            'mensaje'  => $this->mensaje,
            'contexto' => $this->contexto,
        ];
    }
}
