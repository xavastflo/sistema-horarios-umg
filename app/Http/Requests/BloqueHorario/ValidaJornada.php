<?php

namespace App\Http\Requests\BloqueHorario;

use App\Models\CarreraJornada;
use App\Models\Dia;
use Illuminate\Contracts\Validation\Validator;

/**
 * ValidaJornada
 *
 * Trait reutilizable para la validación cruzada de jornada en bloques horarios.
 * Lo usan StoreBloqueHorarioRequest y GenerarBloquesRequest.
 *
 * Reglas de negocio institucionales:
 *   Matutina      → horas 06:00–18:00, días Lunes–Viernes
 *   Vespertina    → horas 18:00–22:00, días Lunes–Viernes
 *   Fin de Semana → horas 06:00–18:00, días Sábado o Domingo
 */
trait ValidaJornada
{
    /** Tabla de reglas indexada por nombre_jornada */
    private function reglasJornada(): array
    {
        return [
            'Matutina' => [
                'hora_min'   => '06:00',
                'hora_max'   => '18:00',
                'dias'       => ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'],
                'label_hora' => '06:00 AM y 06:00 PM',
                'label_dias' => 'Lunes a Viernes',
            ],
            'Vespertina' => [
                'hora_min'   => '18:00',
                'hora_max'   => '22:00',
                'dias'       => ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'],
                'label_hora' => '06:00 PM y 10:00 PM',
                'label_dias' => 'Lunes a Viernes',
            ],
            'Fin de Semana' => [
                'hora_min'   => '06:00',
                'hora_max'   => '18:00',
                'dias'       => ['Sábado', 'Domingo'],
                'label_hora' => '06:00 AM y 06:00 PM',
                'label_dias' => 'Sábado o Domingo',
            ],
        ];
    }

    /**
     * Valida hora_inicio y hora_fin contra el rango de la jornada.
     * Agrega errores al Validator si hay violaciones.
     */
    private function validarHorasJornada(
        Validator $v,
        array     $regla,
        string    $nombreJornada,
        string    $horaInicio,
        string    $horaFin,
        string    $campoInicio = 'hora_inicio',
        string    $campoFin    = 'hora_fin',
    ): void {
        if ($horaInicio < $regla['hora_min'] || $horaInicio >= $regla['hora_max']) {
            $v->errors()->add(
                $campoInicio,
                "Para la jornada {$nombreJornada}, la hora de inicio debe estar entre {$regla['label_hora']}."
            );
        }

        if ($horaFin <= $regla['hora_min'] || $horaFin > $regla['hora_max']) {
            $v->errors()->add(
                $campoFin,
                "Para la jornada {$nombreJornada}, la hora de fin debe estar entre {$regla['label_hora']}."
            );
        }
    }

    /**
     * Valida que un nombre_dia esté entre los permitidos por la jornada.
     * Agrega errores al Validator si hay violaciones.
     */
    private function validarDiaJornada(
        Validator $v,
        array     $regla,
        string    $nombreJornada,
        string    $nombreDia,
        string    $campo = 'id_dia',
    ): void {
        if (! in_array($nombreDia, $regla['dias'])) {
            $v->errors()->add(
                $campo,
                "Para la jornada {$nombreJornada}, el día debe ser {$regla['label_dias']}. "
                . "'{$nombreDia}' no está permitido."
            );
        }
    }

    /**
     * Punto de entrada unificado para withValidator().
     * Resuelve jornada y aplica todas las validaciones cruzadas.
     *
     * @param Validator $v
     * @param int       $idCarreraJornada
     * @param string    $horaInicio       campo simple o general
     * @param string    $horaFin          campo simple o general
     * @param int[]     $idsDia           uno o varios ids de día
     * @param string    $campoHoraInicio  nombre del campo en el request
     * @param string    $campoHoraFin     nombre del campo en el request
     * @param string    $campoDia         nombre del campo de día en el request
     */
    protected function aplicarValidacionJornada(
        Validator $v,
        int       $idCarreraJornada,
        string    $horaInicio,
        string    $horaFin,
        array     $idsDia,
        string    $campoHoraInicio = 'hora_inicio',
        string    $campoHoraFin    = 'hora_fin',
        string    $campoDia        = 'id_dia',
    ): void {
        // Si las reglas básicas ya fallaron no continuar
        if ($v->errors()->isNotEmpty()) return;

        $carreraJornada = CarreraJornada::with('jornada')->find($idCarreraJornada);
        if (! $carreraJornada) return;

        $nombreJornada = $carreraJornada->jornada?->nombre_jornada;
        $regla         = $this->reglasJornada()[$nombreJornada] ?? null;

        // Sin regla → jornada sin restricción definida (extensible)
        if (! $regla) return;

        // Validar horas
        $this->validarHorasJornada($v, $regla, $nombreJornada,
            $horaInicio, $horaFin, $campoHoraInicio, $campoHoraFin);

        // Validar cada día
        foreach ($idsDia as $idDia) {
            $dia = Dia::find((int) $idDia);
            if (! $dia) continue;
            $this->validarDiaJornada($v, $regla, $nombreJornada, $dia->nombre_dia, $campoDia);
        }
    }
}
